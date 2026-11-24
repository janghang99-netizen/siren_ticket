<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Settlement;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OrderWebhookSender;
use App\Services\PgSettlementService;
use App\Services\SettlementReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 원본 다담다 2026-11-20~21 정산 변경: EIS 결제수단 · 페이레터 불러오기 · 카드결제 기준 정산 보고서 · 원샷 판매자 정산 */
class CardSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Vendor $vendor;
    private Store $store;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-21 12:00:00'));   // 정산예정일·확정 판정이 오늘 기준이라 날짜 고정
        $this->admin = User::factory()->create(['role' => 'super_admin']);
        $this->vendor = Vendor::create(['name' => '도봉빵 (Do BON PAIN)', 'state' => 'approved', 'commission_rate' => 8.5]);
        $this->store = Store::create(['vendor_id' => $this->vendor->id, 'code' => 'dobonpang', 'name' => '도봉빵', 'commission_rate' => 8.5]);
        config(['siren.payletter' => ['url' => 'https://pl.test', 'clients' => [
            ['client_id' => 'online1', 'search_key' => 'k1', 'kind' => 'online'],
            ['client_id' => 'online2', 'search_key' => 'k2', 'kind' => 'online'],
            ['client_id' => 'term1', 'search_key' => 'k3', 'kind' => 'terminal', 'store_code' => 'dobonpang'],
        ]]]);
    }

    private function order(string $no, int $amount, string $pay, string $at, int $status = 5, array $extra = []): Order
    {
        return Order::create(['order_no' => $no, 'pickup_code' => str_pad((string) ++$this->seq, 4, '0', STR_PAD_LEFT), 'store_id' => $this->store->id,
            'status' => $status, 'pay_method' => $pay, 'total_amount' => $amount, 'commission_rate' => 8.5, 'created_at' => $at, 'picked_up_at' => $at] + $extra);
    }

    private function pg(string $day, int $amount, bool $terminal = true, string $orderNo = '', string $tid = ''): void
    {
        app(PgSettlementService::class)->insertRows($this->vendor->id, null, [[
            'tid' => $tid ?: 'T'.(++$this->seq), 'approved_on' => $day, 'kind' => $terminal ? 'terminal' : 'online',
            'order_no' => $orderNo, 'status' => $amount > 0 ? '승인' : '취소', 'amount' => $amount,
        ]]);
        DB::table('settle_pg_loads')->updateOrInsert(['vendor_id' => $this->vendor->id, 'label' => 'test'], ['approved_to' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_eis_rows_carry_pay_method_label(): void
    {
        $this->assertSame('카드', OrderWebhookSender::payMethodLabel('card'));
        $this->assertSame('카드', OrderWebhookSender::payMethodLabel('pos_card'));
        $this->assertSame('현금', OrderWebhookSender::payMethodLabel('pos_cash'));
        $this->assertSame('이체', OrderWebhookSender::payMethodLabel('pos_transfer'));
        $this->assertSame('현금', OrderWebhookSender::payMethodLabel('마일리지결제', '[현금] 소금빵'));
        $this->assertSame('이체', OrderWebhookSender::payMethodLabel('마일리지결제', '[계좌이체] 소금빵'));
        $this->assertSame('카드', OrderWebhookSender::payMethodLabel('마일리지결제', '소금빵'));
        $this->assertSame('이체', OrderWebhookSender::payMethodLabel('무통장입금'));
        $this->assertSame('카드', OrderWebhookSender::payMethodLabel('카카오페이'));
        $this->assertSame('', OrderWebhookSender::payMethodLabel(''));

        $o = $this->order('D-1', 5000, 'pos_cash', '2026-11-12 10:00:00');
        $o->items()->create(['product_id' => \App\Models\Product::create(['store_id' => $this->store->id, 'name' => '소금빵', 'price' => 5000])->id,
            'name' => '소금빵', 'unit_price' => 5000, 'qty' => 1, 'line_total' => 5000]);
        $this->assertSame('현금', OrderWebhookSender::payloadRows($o->fresh())[0]['pay_method']);
    }

    /** 원본 검증값: 도봉빵 카드 3,898,960 → 이용료 8.5%·부가세 10% → 실제 송금 3,534,407 (옛 주문 기준 3,591,688) */
    public function test_card_basis_payout_matches_original_transfer(): void
    {
        $this->pg('2026-06-09', 3_000_000);
        $this->pg('2026-11-05', 898_960);
        $c = app(PgSettlementService::class)->cardSettle($this->vendor, '2026-06-09', '2026-11-05');
        $this->assertTrue($c['ok']);
        $this->assertSame(3_898_960, $c['card']);
        $this->assertSame(331_412, $c['fee']);
        $this->assertSame(33_141, $c['vat']);
        $this->assertSame(3_534_407, $c['pay']);   // round(card − fee − vat), 반올림 전 값으로
        $this->assertFalse(app(PgSettlementService::class)->cardSettle(Vendor::create(['name' => '빈 업체']), '2026-06-09', '2026-11-05')['ok']);
    }

    public function test_payletter_day_load_keeps_own_orders_terminal_rows_and_dedupes(): void
    {
        $this->order('MINE-1', 12000, 'card', '2026-11-12 09:00:00');
        Http::fake(function (HttpRequest $r) {
            $this->assertSame('PLKEY '.['online1' => 'k1', 'online2' => 'k2', 'term1' => 'k3'][$r['client_id']], $r->header('Authorization')[0]);
            $this->assertSame('20261112', $r['date']);
            $this->assertSame('settle', $r['date_type']);
            $list = match ($r['client_id']) {
                'online1' => [
                    ['tid' => 'O1', 'cid' => 'A1', 'transaction_date' => '2026-11-12 09:00:01', 'status_code' => '0', 'amount' => '12000', 'order_no' => 'MINE-1', 'pgcode' => 'creditcard'],
                    ['tid' => 'O2', 'cid' => 'A2', 'transaction_date' => '2026-11-12 09:10:00', 'status_code' => '0', 'amount' => '9000', 'order_no' => 'OTHER-9', 'pgcode' => 'creditcard'],
                ],
                'online2' => [],
                'term1' => [
                    ['tid' => 'K1', 'cid' => 'B1', 'transaction_date' => '2026-11-12 11:00:00', 'status_code' => '0', 'amount' => '7000', 'order_no' => '', 'pgcode' => 'terminal'],
                    ['tid' => 'K0', 'cid' => 'B0', 'transaction_date' => '2026-11-10 11:00:00', 'status_code' => '1', 'amount' => '3000', 'order_no' => '', 'pgcode' => 'terminal'],
                    ['tid' => 'K9', 'cid' => 'B9', 'transaction_date' => '2026-11-12 12:00:00', 'status_code' => '2', 'amount' => '500', 'order_no' => '', 'pgcode' => 'terminal'],
                ],
            };

            return Http::response(['list' => $list], 200);
        });

        $this->actingAs($this->admin);
        $res = $this->postJson(route('admin.settlements.pg-load'), ['vendor_id' => $this->vendor->id, 'date' => '2026-11-12', 'label' => '불러오기'])
            ->assertOk()->assertJson(['ok' => true, 'got' => 3, 'new' => 3, 'partial' => 1, 'other' => 1])->json();
        $this->assertSame(3, DB::table('settle_pg_rows')->count());
        $this->assertSame(-3000, (int) DB::table('settle_pg_rows')->where('tid', 'K0')->value('amount'));   // 취소 = −금액, 원 결제일
        $this->assertSame('2026-11-10', substr(DB::table('settle_pg_rows')->where('tid', 'K0')->value('approved_on'), 0, 10));
        $load = DB::table('settle_pg_loads')->find($res['load_id']);
        $this->assertSame('2026-11-10', substr($load->approved_from, 0, 10));
        $this->assertSame('2026-11-12', substr($load->approved_to, 0, 10));
        $this->assertSame(['2026-11-12', '2026-11-12'], [substr($load->queried_from, 0, 10), substr($load->queried_to, 0, 10)]);   // 확정 판정용 조회 범위

        // 다시 불러와도 중복 저장 없음
        $this->postJson(route('admin.settlements.pg-load'), ['vendor_id' => $this->vendor->id, 'date' => '2026-11-12'])->assertJson(['got' => 3, 'new' => 0]);
        $this->assertSame(3, DB::table('settle_pg_rows')->count());

        // 단말기 가맹점은 그 매장 업체에서만 부른다
        $other = Vendor::create(['name' => '다른 업체']);
        Store::create(['vendor_id' => $other->id, 'code' => 'zzz', 'name' => '다른 매장']);
        $this->postJson(route('admin.settlements.pg-load'), ['vendor_id' => $other->id, 'date' => '2026-11-12'])->assertJson(['got' => 0, 'other' => 2]);

        $this->deleteJson(route('admin.settlements.pg-load.destroy', [$this->vendor, $res['load_id']]))->assertJson(['ok' => true, 'deleted' => 3]);
        $this->assertSame(0, DB::table('settle_pg_rows')->count());
    }

    public function test_load_without_keys_is_refused(): void
    {
        config(['siren.payletter' => ['url' => '', 'clients' => []]]);
        $this->actingAs($this->admin)->postJson(route('admin.settlements.pg-load'), ['vendor_id' => $this->vendor->id, 'date' => '2026-11-12'])
            ->assertStatus(422)->assertJson(['ok' => false]);
    }

    /** 원본 2026-11-24판: 보고서 = 원본 데이터 3구역을 가공 없이 그대로 (짝 맞추기·재분류·입금일 추정 없음) */
    public function test_report_lists_three_raw_sections(): void
    {
        $this->order('OLD-1', 10000, 'pos_card', '2026-11-03 10:00:00', 6, ['settled_at' => '2026-11-05 09:00:00']);
        $this->order('ON-1', 12000, 'card', '2026-11-10 09:00:00');
        $this->order('CASH-1', 6000, 'pos_cash', '2026-11-11 12:00:00');
        $this->order('PREP-1', 3000, 'pos_card', '2026-11-12 13:00:00', 3);     // 수령 전
        $this->order('OUT-1', 9999, 'pos_card', '2026-11-20 10:00:00');          // 기간 밖
        Settlement::create(['vendor_id' => $this->vendor->id, 'period_start' => '2026-11-01', 'period_end' => '2026-11-04', 'order_count' => 1,
            'gross_amount' => 10000, 'commission_amount' => 0, 'payout_amount' => 10000]);
        Order::where('order_no', 'OLD-1')->update(['settlement_id' => Settlement::first()->id]);
        $this->pg('2026-11-02', 10000);                                          // 완료 묶음 기간(첫 PG 승인일 ~ 마지막 주문일) 안
        $this->pg('2026-11-10', 12000, false, 'ON-1');
        $this->pg('2026-11-11', 1500);
        $this->pg('2026-11-11', -1500);

        $r = app(SettlementReport::class)->build($this->vendor, '2026-11-01', '2026-11-13', 8.5);
        $s = $r['sections'];
        $this->assertSame('도봉빵', $r['store']);   // 상호 끝 괄호 제거
        // 1. 결제내역 = 주문 그대로 (기간 밖 제외)
        $this->assertSame(['합계', 4, 31000], end($s['s1']['sum']));
        $this->assertSame(['2026-11-11 12:00', 'CASH-1', '현장 현금', 6000], $s['s1']['rows'][2]);
        // 2. 정산내역 = 상태·처리일·정산예정일
        $this->assertSame([['정산완료', 1, 10000], ['미정산 (수령완료)', 2, 18000], ['수령 전', 1, 3000], ['합계', 4, 31000]], $s['s2']['sum']);
        $this->assertSame([['2026-11-05 09:00', 1, 10000]], $s['s2']['byJd']);
        $this->assertSame(['2026-11-10 09:00', 'ON-1', 12000, '미정산 (수령완료)', '', '2026-11-17'], $s['s2']['rows'][1]);
        // 3. PG 정산내역 = 승인·취소 그대로 + 원샷 정산 여부 + 정산금액
        $this->assertSame([['승인', 3, 23500], ['취소', 1, -1500], ['카드결제 합계 (승인 − 취소)', 4, 22000]], array_slice($s['s3']['sum'], 0, 3));
        $this->assertSame((int) round(22000 - 22000 * 0.085 * 1.1), $s['s3']['pay']);
        $this->assertSame('정산완료 2026-11-05 09:00', end($s['s3']['rows'][0]));
        $this->assertSame('미정산', end($s['s3']['rows'][1]));
        $this->assertSame('ON-1', $s['s3']['rows'][1][5]);
        $this->assertSame('지급할 정산금액', $s['s3']['payLab']);
        $this->assertSame('정산 송금 완료 금액', app(SettlementReport::class)->build($this->vendor, '2026-11-01', '2026-11-04', 8.5, 'done')['sections']['s3']['payLab']);

        $this->actingAs($this->admin)->get(route('admin.settlements.report', ['vendor' => $this->vendor->id, 'from' => '2026-11-01', 'to' => '2026-11-13']))
            ->assertOk()->assertSee('도봉빵 정산 보고서')->assertSee('1. 다담다 결제내역')->assertSee('2. 다담다 정산내역')->assertSee('3. PG사(페이레터) 정산내역');
        $res = $this->get(route('admin.settlements.report.export', ['vendor' => $this->vendor->id, 'from' => '2026-11-01', 'to' => '2026-11-13']))->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, $res->getContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $wb = $zip->getFromName('xl/workbook.xml');
        foreach (['1.다담다결제내역', '2.다담다정산내역', '3.PG정산내역'] as $sheet) {
            $this->assertStringContainsString($sheet, $wb);
        }
        $this->assertStringContainsString('지급할 정산금액', $zip->getFromName('xl/worksheets/sheet3.xml'));
        $zip->close();
        @unlink($tmp);
    }

    public function test_oneshot_pending_list_shows_card_basis_and_settles_vendor(): void
    {
        $this->order('OLD-1', 10000, 'pos_card', '2026-11-01 10:00:00', 6);      // 이전 정산 → 다음 묶음은 11/02 부터
        $this->order('NEW-1', 20000, 'pos_card', '2026-11-05 10:00:00');
        $this->order('NEW-2', 5000, 'pos_cash', '2026-11-06 10:00:00');
        $this->pg('2026-11-01', 10000);
        $this->pg('2026-11-04', 3000);                                            // 주문 없는 날 카드결제도 기간에 포함
        $this->pg('2026-11-05', 20000);

        $pg = app(PgSettlementService::class);
        $this->assertSame(['2026-11-02', '2026-11-06'], $pg->pendingWindow($this->vendor));
        $this->assertSame(['2026-11-03', '2026-11-06'], $pg->pendingWindow($this->vendor, '2026-11-03'));

        $this->actingAs($this->admin)->get(route('admin.settlements.index'))->assertOk()
            ->assertSee('원샷 판매자 정산 대기목록')->assertSee('카드결제 기준')
            ->assertSee(number_format((int) round(23000 - 23000 * 0.085 * 1.1)))     // 카드 23,000 기준 정산금액
            ->assertSee('주문 기준');

        $this->post(route('admin.settlements.oneshot', $this->vendor))->assertRedirect();
        $s = Settlement::firstOrFail();
        $this->assertSame(2, $s->order_count);                                     // 정산 처리는 기존 계산 그대로 (주문 2건)
        $this->assertSame(25000, $s->gross_amount);
        $this->assertSame(0, Order::where('status', 5)->count());
        // 완료목록: 첫 정산서는 첫 주문일과 PG 첫 승인일 중 빠른 날부터
        $this->assertSame(['2026-11-01', '2026-11-06'], $pg->bundleWindow($s));
        $this->get(route('admin.settlements.index'))->assertOk()->assertSee('정산 완료목록')->assertSee('src=done', false);
        // 정산서 상세: 카드 기준 금액·설명·보고서 버튼 (지급액은 정산서 값 그대로)
        $this->get(route('admin.settlements.show', $s))->assertOk()->assertSee('카드결제 기준 정산금액')
            ->assertSee(number_format((int) round(33000 - 33000 * 0.085 * 1.1)))->assertSee(number_format($s->payout_amount));
    }

    /** 원본 #7: 원샷 판매자 정산 = 정산예정일(주문일 + 5일)이 지난 수령완료 주문만 — 대기목록·엑셀·[정산완료] 같은 대상, 나머지는 노란 상자 */
    public function test_oneshot_settles_only_orders_past_due_date(): void
    {
        $this->vendor->update(['bank_name' => '테스트은행', 'bank_account' => '000-00-000000', 'account_holder' => '도봉빵']);
        $this->assertSame('7', \App\Models\Setting::get('settle_due_days'));      // 기본값 = 원본 실서버 설정 (2026-11-21 5→7일)
        \App\Models\Setting::put(['settle_due_days' => 5]);                       // 아래 날짜는 5일 기준
        $this->order('DUE-1', 10000, 'pos_card', '2026-11-15 10:00:00');          // 정산예정일 11-20
        $this->order('DUE-2', 7000, 'pos_card', '2026-11-16 23:00:00');           // 11-21 = 오늘 → 대상
        $this->order('WAIT-1', 3000, 'pos_card', '2026-11-17 01:00:00');          // 11-22 → 아직
        $this->order('WAIT-2', 4000, 'pos_card', '2026-11-19 09:00:00');          // 11-24 → 아직

        $this->assertSame('2026-11-17 00:00:00', PgSettlementService::dueCutoff()->toDateTimeString());
        $this->assertSame(['2026-11-15', '2026-11-16'], app(PgSettlementService::class)->pendingWindow($this->vendor));   // [보고서] 끝 = 대상 주문의 마지막 주문일

        $this->actingAs($this->admin)->get(route('admin.settlements.index'))->assertOk()
            ->assertSee('정산예정일이 아직 안 된 수령완료 주문')
            ->assertSee('<span class="num">7,000</span>원 (가장 빠른 정산예정일 <span class="num">2026-11-22</span>)', false)
            ->assertSee('주문일 2026-11-15 ~ 2026-11-16')->assertSee('17,000');

        $csv = $this->get(route('admin.settlements.oneshot.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('000-00-000000', $csv);
        $this->assertStringContainsString(',17000,', $csv);                       // 엑셀도 대상(17,000)만
        $this->assertStringNotContainsString('24000', $csv);

        $this->post(route('admin.settlements.oneshot', $this->vendor))->assertRedirect();
        $s = Settlement::firstOrFail();
        $this->assertSame(2, $s->order_count);
        $this->assertSame(17000, $s->gross_amount);
        $this->assertEqualsCanonicalizing(['WAIT-1', 'WAIT-2'], Order::where('status', 5)->pluck('order_no')->all());   // 정산예정일 전 주문은 그대로

        $this->get(route('admin.settlements.index'))->assertOk()
            ->assertSee('업체에게 정산할 내역이 없어요')->assertSee('정산예정일이 아직 안 된 수령완료 주문');   // 대상이 없어도 노란 상자

        // 설정을 바꾸면 그 기준으로 (0일 = 수령완료 즉시 대상)
        \App\Models\Setting::put(['settle_due_days' => 0]);
        $this->assertSame(2, app(\App\Services\SettlementService::class)->pendingByVendor()->first()['count']);
    }

    /** 원본에서 옮겨 온 수령완료 주문도 원샷 대상 — 정산예정일은 원본 저장값(옛 주문 5일), 노란 상자 줄 수 = 상품 줄 */
    public function test_imported_orders_use_stored_due_date_and_lines_count(): void
    {
        $bread = \App\Models\Product::create(['store_id' => $this->store->id, 'name' => '빵', 'price' => 1000]);
        $imp = fn (string $no, int $amt, string $at, string $due, int $lines) => tap($this->order($no, $amt, 'pos_card', $at, 5, ['imported_from' => 'dadamda', 'settle_due_on' => $due]),
            function (Order $o) use ($lines, $amt, $bread) {
                for ($i = 0; $i < $lines; $i++) {
                    $o->items()->create(['product_id' => $bread->id, 'name' => '빵', 'unit_price' => intdiv($amt, $lines), 'qty' => 1, 'line_total' => intdiv($amt, $lines), 'dine_option' => '포장']);
                }
            });
        $imp('IMP-1', 10000, '2026-11-16 11:00:00', '2026-11-21', 2);   // 저장된 예정일 = 오늘 → 대상 (설정 7일이면 11-23 이지만 원본 값 그대로)
        $imp('IMP-2', 6000, '2026-11-17 10:00:00', '2026-11-22', 3);    // 내일 → 노란 상자
        $imp('IMP-3', 4000, '2026-11-20 10:00:00', '2026-11-27', 1);
        $this->order('NEW-1', 5000, 'pos_card', '2026-11-10 10:00:00');  // 새 시스템 주문 = 주문일 + 7일 = 11-17 → 대상

        $svc = app(\App\Services\SettlementService::class);
        $this->assertSame(15000, $svc->pendingByVendor()->first()['gross']);
        $nd = $svc->notDueByVendor()->first();
        $this->assertSame([4, 2, 10000, '2026-11-22'], [$nd['count'], $nd['orders'], $nd['amount'], $nd['first_due']]);

        $this->actingAs($this->admin)->post(route('admin.settlements.oneshot', $this->vendor))->assertRedirect();
        $this->assertSame(15000, Settlement::firstOrFail()->gross_amount);
        $this->assertEqualsCanonicalizing(['IMP-2', 'IMP-3'], Order::where('status', 5)->pluck('order_no')->all());
        // 기간 정산(자동 정산 경로)은 옮겨 온 주문을 여전히 빼고 정산하지 않는다
        $this->assertTrue($svc->generate(\Illuminate\Support\Carbon::parse('2026-11-01'), \Illuminate\Support\Carbon::parse('2026-11-30'))->isEmpty());
    }

    /** 원본 #6: 승인일 d 는 d 를 포함해 d+10일 이후에 조회한 기록이 있어야 확정 → 미확정 첫 날 경고 */
    public function test_late_payletter_rows_need_confirmed_query(): void
    {
        $pg = app(PgSettlementService::class);
        $load = fn (string $f, string $t, string $at) => DB::table('settle_pg_loads')->insert(['vendor_id' => $this->vendor->id, 'label' => 'q', 'approved_from' => $f, 'approved_to' => $t,
            'queried_from' => $f, 'queried_to' => $t, 'created_at' => $at, 'updated_at' => $at]);
        $load('2026-06-01', '2026-11-20', '2026-11-21 01:09:00');
        $load('2026-11-06', '2026-11-20', '2026-11-21 09:09:00');

        $this->assertSame('2026-11-12', $pg->unfinalFrom($this->vendor, '2026-11-06', '2026-11-16'));   // 11-12 + 10 = 11-22 > 조회일 11-21
        $this->assertSame('', $pg->unfinalFrom($this->vendor, '2026-06-09', '2026-11-05'));             // 완료된 옛 묶음은 모두 확정
        $this->assertSame('2026-11-21', $pg->unfinalFrom($this->vendor, '2026-11-21', '2026-11-21'));   // 조회 안 한 날

        $this->pg('2026-11-10', 263080);
        $c = $pg->cardSettle($this->vendor, '2026-11-06', '2026-11-16');
        $this->assertTrue($c['short']);
        $this->assertSame('2026-11-12', $c['unfinal']);
        $this->assertSame(238482, $c['pay']);                                      // 실서버 확인값: 카드 263,080 → 238,482

        \App\Models\Setting::put(['settle_due_days' => 5]);                       // 대상 주문일 11-06~11-16 (원본 5일 기준 확인값)
        $this->order('A-1', 263080, 'pos_card', '2026-11-06 10:00:00');
        $this->order('A-2', 1000, 'pos_card', '2026-11-16 10:00:00');
        $this->actingAs($this->admin)->get(route('admin.settlements.index'))->assertOk()
            ->assertSee('2026-11-12 이후 카드 결제가 덜 반영됐을 수 있음 → [보고서]로 대사');

        // 12-01 에 다시 조회하면 모두 확정
        $load('2026-11-06', '2026-11-30', '2026-12-01 09:00:00');
        $this->assertSame('', $pg->unfinalFrom($this->vendor, '2026-11-06', '2026-11-16'));
    }

    /** 원본 #5·#6: 원샷 [보고서]로 열면 수동 불러오기 칸 없이 미확정 첫 날 ~ 끝을 자동 대사 */
    public function test_report_opened_from_oneshot_auto_reconciles(): void
    {
        DB::table('settle_pg_loads')->insert(['vendor_id' => $this->vendor->id, 'label' => 'q', 'approved_from' => '2026-11-01', 'approved_to' => '2026-11-20',
            'queried_from' => '2026-11-01', 'queried_to' => '2026-11-20', 'created_at' => '2026-11-21 01:00:00', 'updated_at' => '2026-11-21 01:00:00']);
        $this->pg('2026-11-08', 5000);
        $url = fn (array $q = []) => route('admin.settlements.report', ['vendor' => $this->vendor->id, 'from' => '2026-11-06', 'to' => '2026-11-16', 'src' => 'pending'] + $q);

        $this->actingAs($this->admin)->get($url())->assertOk()
            ->assertSee('"from":"2026-11-12","to":"2026-11-16"', false)               // 자동 대사 범위 = 미확정 첫 날 ~ 끝
            ->assertSee('카드 결제 내역을 맞추는 중')->assertDontSee('id="plGo"', false)->assertDontSee('저장 일시');
        $this->get($url(['synced' => 1]))->assertOk()->assertSee('✓ 카드 결제 대사 완료')->assertSee('const SR_SYNC = null', false);
        $this->get($url(['synced' => 1, 'syncerr' => 2]))->assertOk()->assertSee('✕ 카드 결제 대사 일부 실패 (2일)')->assertSee('다시 맞추기');
        // 완료된 옛 묶음(모두 확정)은 조회 없이 바로 완료
        $this->get(route('admin.settlements.report', ['vendor' => $this->vendor->id, 'from' => '2026-11-01', 'to' => '2026-11-05', 'src' => 'done']))
            ->assertOk()->assertSee('const SR_SYNC = null', false)->assertSee('✓ 카드 결제 대사 완료');
        // 기간 없이 열면 예전처럼 수동 불러오기·기록 표
        $this->get(route('admin.settlements.report', ['vendor' => $this->vendor->id]))->assertOk()->assertSee('id="plGo"', false)->assertSee('저장 일시');
        // 조회 키가 없으면 안내
        config(['siren.payletter' => ['url' => '', 'clients' => []]]);
        $this->get($url())->assertOk()->assertSee('페이레터 조회 키 설정이 없어')->assertSee('const SR_SYNC = null', false);
    }

    /** 원본 #7 보안: 원샷 엑셀이 로그인 없이 계좌·정산금액을 내려줬음 → 이관 앱의 정산 엑셀·보고서·불러오기는 운영사만 */
    public function test_settlement_exports_and_loads_require_super_admin(): void
    {
        $vendorUser = User::factory()->create(['role' => 'vendor', 'vendor_id' => $this->vendor->id]);
        $gets = [route('admin.settlements.index'), route('admin.settlements.oneshot.export'), route('admin.settlements.report', ['vendor' => $this->vendor->id]),
            route('admin.settlements.report.export', ['vendor' => $this->vendor->id])];

        foreach ($gets as $u) {
            $this->get($u)->assertRedirect(route('login'));
        }
        $this->post(route('admin.settlements.oneshot', $this->vendor))->assertRedirect(route('login'));
        $this->postJson(route('admin.settlements.pg-load'), ['vendor_id' => $this->vendor->id, 'date' => '2026-11-12'])->assertUnauthorized();
        $this->deleteJson(route('admin.settlements.pg-load.destroy', [$this->vendor, 1]))->assertUnauthorized();

        $this->actingAs($vendorUser);
        foreach ($gets as $u) {
            $this->get($u)->assertForbidden();
        }
        $this->post(route('admin.settlements.oneshot', $this->vendor))->assertForbidden();
        $this->postJson(route('admin.settlements.pg-load'), ['vendor_id' => $this->vendor->id, 'date' => '2026-11-12'])->assertForbidden();
        $this->deleteJson(route('admin.settlements.pg-load.destroy', [$this->vendor, 1]))->assertForbidden();
        $this->assertSame(0, Settlement::count());
    }
}
