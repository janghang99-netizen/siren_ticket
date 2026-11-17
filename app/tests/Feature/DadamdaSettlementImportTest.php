<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Settlement;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 다담다 이관: arena1 정산 — 원본 '정산 완료목록' 1회(2026-11-12)와 '정산 대기' */
class DadamdaSettlementImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('dadamda:import-products', ['--store' => 'dobonpang'])->assertSuccessful();
        $this->artisan('dadamda:import-orders', ['--store' => 'dobonpang'])->assertSuccessful();
    }

    public function test_original_settlement_matches_and_links_orders(): void
    {
        $this->artisan('dadamda:import-settlements', ['--store' => 'dobonpang'])
            ->expectsOutputToContain('정산서 1장 · 묶은 주문 343건')
            ->expectsOutputToContain('원본 정산 대기 123,300원 ↔ 옮긴 수령완료 123,300원 (10건) — 일치')
            ->assertSuccessful();

        $s = Settlement::sole();
        $this->assertSame('dadamda:arena1:2026-11-12 00:30:15', $s->legacy_ref);
        $this->assertSame(4068780, (int) $s->gross_amount);
        $this->assertSame(348777, (int) $s->commission_amount + (int) $s->commission_vat);   // 원본 수수료(부가세 포함)
        $this->assertSame(31707, (int) $s->commission_vat);
        $this->assertSame(786500, (int) $s->platform_benefit_amount);
        $this->assertSame(3720003, (int) $s->payout_amount);
        $this->assertSame('paid', $s->status);
        $this->assertSame('2026-11-11', $s->period_end->toDateString());

        $this->assertSame(343, Order::where('settlement_id', $s->id)->count());
        $this->assertSame(0, Order::where('status', OrderStatus::Settled->value)->whereNull('settlement_id')->count());
        $this->assertSame(10, Order::where('status', OrderStatus::PickedUp->value)->whereNull('settlement_id')->count());   // 원본 정산 대기

        $vendor = Store::where('code', 'dobonpang')->firstOrFail()->vendor;
        $this->assertSame('117-87-03645', $vendor->business_no);
        $this->assertNull($vendor->bank_account);   // 계좌는 옮기지 않는다
    }

    public function test_running_again_does_not_duplicate(): void
    {
        $this->artisan('dadamda:import-settlements', ['--store' => 'dobonpang'])->assertSuccessful();
        $this->artisan('dadamda:import-settlements', ['--store' => 'dobonpang'])
            ->expectsOutputToContain('정산서 0장 · 묶은 주문 0건 · 이미 있음 1장')
            ->assertSuccessful();
        $this->assertSame(1, Settlement::count());
    }

    /** 정산 대기 10건은 원본에서 정산한다 → 원본 정산 뒤 판매·정산을 다시 가져오면 새 정산서로 들어온다 */
    public function test_next_original_settlement_is_picked_up_on_reimport(): void
    {
        $this->artisan('dadamda:import-settlements', ['--store' => 'dobonpang'])->assertSuccessful();

        // 원본이 다음 정산을 했다고 가정: 수령완료 10건이 정산완료로 바뀌고 정산 기록이 하나 늘어난다
        $dir = sys_get_temp_dir();
        $orders = json_decode((string) file_get_contents(base_path('database/import/dadamda/arena1_orders.json')), true);
        foreach ($orders as &$o) {
            if ($o['status'] === '수령완료') {
                $o['status'] = '정산완료';
                $o['settled'] = '2026-11-20 00:30:00';   // 원본 주문내역의 정산일시
            }
        }
        unset($o);
        file_put_contents($ordersFile = "{$dir}/ddm_orders_next.json", json_encode($orders, JSON_UNESCAPED_UNICODE));
        $settlements = json_decode((string) file_get_contents(base_path('database/import/dadamda/arena1_settlements.json')), true);
        $settlements['settlements'][] = ['at' => '2026-11-20 00:30:00', 'items' => 28, 'gross' => 123300, 'fee' => 11535, 'mileage_used' => 0, 'net' => 111765];
        file_put_contents($settleFile = "{$dir}/ddm_settle_next.json", json_encode($settlements, JSON_UNESCAPED_UNICODE));

        $this->artisan('dadamda:import-orders', ['file' => $ordersFile, '--store' => 'dobonpang'])
            ->expectsOutputToContain('원본에서 상태가 바뀐 주문 10건 갱신')
            ->assertSuccessful();
        $this->artisan('dadamda:import-settlements', ['file' => $settleFile, '--store' => 'dobonpang'])
            ->expectsOutputToContain('정산서 1장 · 묶은 주문 10건 · 이미 있음 1장')
            ->assertSuccessful();

        $next = Settlement::where('legacy_ref', 'dadamda:arena1:2026-11-20 00:30:00')->firstOrFail();
        $this->assertSame(111765, (int) $next->payout_amount);
        $this->assertSame(0, Order::whereNull('settlement_id')->where('status', OrderStatus::Settled->value)->count());
        @unlink($ordersFile);
        @unlink($settleFile);
    }

    /** 운영 서버에 처음 넣을 때 원본 정산이 이미 두 번이면: 정산일시로 회차를 나눈다 (11-11 주문 2건은 11-12 회차가 아니라 다음 회차) */
    public function test_fresh_import_with_two_original_settlements_uses_settled_time(): void
    {
        $dir = sys_get_temp_dir();
        $orders = json_decode((string) file_get_contents(base_path('database/import/dadamda/arena1_orders.json')), true);
        foreach ($orders as &$o) {
            if ($o['status'] === '정산완료') {
                $o['settled'] = '2026-11-12 00:30:15';
            } elseif ($o['status'] === '수령완료') {
                [$o['status'], $o['settled']] = ['정산완료', '2026-11-20 00:30:00'];
            }
        }
        unset($o);
        file_put_contents($ordersFile = "{$dir}/ddm_orders_fresh.json", json_encode($orders, JSON_UNESCAPED_UNICODE));
        $settlements = json_decode((string) file_get_contents(base_path('database/import/dadamda/arena1_settlements.json')), true);
        $settlements['settlements'][] = ['at' => '2026-11-20 00:30:00', 'items' => 28, 'gross' => 123300, 'fee' => 11535, 'mileage_used' => 0, 'net' => 111765];
        file_put_contents($settleFile = "{$dir}/ddm_settle_fresh.json", json_encode($settlements, JSON_UNESCAPED_UNICODE));

        Order::where('imported_from', 'dadamda')->delete();
        $this->artisan('dadamda:import-orders', ['file' => $ordersFile, '--store' => 'dobonpang'])->assertSuccessful();
        $this->artisan('dadamda:import-settlements', ['file' => $settleFile, '--store' => 'dobonpang'])
            ->expectsOutputToContain('정산서 2장 · 묶은 주문 353건')
            ->assertSuccessful();

        $this->assertSame(343, Settlement::where('legacy_ref', 'dadamda:arena1:2026-11-12 00:30:15')->sole()->order_count);
        $this->assertSame(10, Settlement::where('legacy_ref', 'dadamda:arena1:2026-11-20 00:30:00')->sole()->order_count);
        @unlink($ordersFile);
        @unlink($settleFile);
    }

    public function test_refuses_when_amounts_differ(): void
    {
        Order::where('status', OrderStatus::Settled->value)->firstOrFail()->delete();   // 정산된 주문 하나가 빠진 상태
        $this->artisan('dadamda:import-settlements', ['--store' => 'dobonpang'])
            ->expectsOutputToContain('원본과 옮긴 주문이 달라 넣지 않았어요')
            ->assertFailed();
        $this->assertSame(0, Settlement::count());
    }
}
