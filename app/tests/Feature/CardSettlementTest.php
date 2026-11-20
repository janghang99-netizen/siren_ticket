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

    public function test_report_matches_orders_to_card_approvals(): void
    {
        $this->order('ON-1', 12000, 'card', '2026-11-10 09:00:00');               // 온라인 = 주문번호+금액
        $this->order('POS-1', 4500, 'pos_card', '2026-11-09 10:00:00');           // 단말기 = 같은 금액, 1일 차이
        $this->order('POS-2', 9000, 'pos_card', '2026-11-11 10:00:00');           // 카드 2번으로 나눠 결제 (4000+5000)
        $this->order('CASH-1', 6000, 'pos_cash', '2026-11-11 12:00:00');          // 현금 입력, 실제는 카드 (±3일)
        $this->order('CASH-2', 3000, 'pos_transfer', '2026-11-11 13:00:00');      // 매장 직접 수령
        $this->order('POS-3', 2000, 'pos_card', '2026-11-11 14:00:00');           // 카드 승인 없음
        $this->pg('2026-11-10', 12000, false, 'ON-1');
        $this->pg('2026-11-10', 4500);
        $this->pg('2026-11-11', 4000);
        $this->pg('2026-11-11', 5000);
        $this->pg('2026-11-13', 6000);
        $this->pg('2026-11-11', 777);                                             // 주문 없는 카드 결제
        $this->pg('2026-11-11', 1500);
        $this->pg('2026-11-11', -1500);                                           // 같은 날 취소 → 상쇄

        $r = app(SettlementReport::class)->build($this->vendor, '2026-11-09', '2026-11-13', 8.5);
        $s = $r['sections'];
        $this->assertSame('도봉빵', $r['store']);   // 상호 끝 괄호 제거
        $this->assertSame(32277, $s['card']);
        $this->assertSame((int) round(32277 - 32277 * 0.085 * 1.1), $s['pay']);
        $this->assertSame([['2026-11-11 12:00', '현장 현금', '2026-11-13', 6000, 'CASH-1']], $s['wrong'][0]);
        $this->assertSame([['2026-11-11 13:00', '현장 계좌이체', 3000, 'CASH-2']], $s['direct'][0]);
        $this->assertSame([['2026-11-11', '카드단말기', 777, '']], $s['pgOnly'][0]);
        $this->assertSame([['2026-11-11 14:00', '현장 카드단말기', 2000, 'POS-3']], $s['sysOnly'][0]);
        $this->assertSame('POS-2', $s['split'][0][0][3]);
        $this->assertSame(32277, end($s['bridge'][0])[1]);

        $this->actingAs($this->admin)->get(route('admin.settlements.report', ['vendor' => $this->vendor->id, 'from' => '2026-11-09', 'to' => '2026-11-13']))
            ->assertOk()->assertSee('도봉빵 정산 보고서')->assertSee('현금·계좌이체로 주문했지만 카드로 결제된 건')->assertSee('원샷 정산 처리 현황');
        $csv = $this->get(route('admin.settlements.report.export', ['vendor' => $this->vendor->id, 'from' => '2026-11-09', 'to' => '2026-11-13']))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('지급할 정산금액', $csv);
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
    }

    public function test_vendor_cannot_open_report(): void
    {
        $vendorUser = User::factory()->create(['role' => 'vendor', 'vendor_id' => $this->vendor->id]);
        $this->actingAs($vendorUser)->get(route('admin.settlements.report'))->assertForbidden();
        $this->postJson(route('admin.settlements.pg-load'), ['vendor_id' => $this->vendor->id, 'date' => '2026-11-12'])->assertForbidden();
    }
}
