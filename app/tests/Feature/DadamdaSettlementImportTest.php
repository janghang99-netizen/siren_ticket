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

    public function test_refuses_when_amounts_differ(): void
    {
        Order::where('status', OrderStatus::Settled->value)->firstOrFail()->delete();   // 정산된 주문 하나가 빠진 상태
        $this->artisan('dadamda:import-settlements', ['--store' => 'dobonpang'])
            ->expectsOutputToContain('원본과 옮긴 주문이 달라 넣지 않았어요')
            ->assertFailed();
        $this->assertSame(0, Settlement::count());
    }
}
