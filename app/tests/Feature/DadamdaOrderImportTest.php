<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Settlement;
use App\Services\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 다담다 이관: arena1(도봉빵) 판매 기록 — 원본 관리자 '주문내역리스트'에서 읽은 448건 중 결제된 401건 */
class DadamdaOrderImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('dadamda:import-products', ['--store' => 'dobonpang'])->assertSuccessful();
    }

    public function test_imports_paid_orders_without_buyer_info(): void
    {
        $this->artisan('dadamda:import-orders', ['--store' => 'dobonpang'])
            ->expectsOutputToContain('주문 401건 · 4,522,180원 가져옴 · 이미 있음 0건 · 입금대기(미결제) 제외 47건')
            ->expectsOutputToContain('469 차이라떼(HOT/ICE)')
            ->assertSuccessful();

        $this->assertSame(401, Order::where('imported_from', 'dadamda')->count());
        $this->assertSame(343, Order::where('status', OrderStatus::Settled->value)->count());
        $this->assertSame(38, Order::where('status', OrderStatus::Refunded->value)->count());
        $this->assertSame(0, Order::whereNotNull('buyer_phone')->count());   // 구매자 정보는 가져오지 않는다
        $this->assertSame(0, Order::where('status', OrderStatus::Pending->value)->count());

        // POS 주문 한 건: 5개 상품 14,800원, 현장카드, 전광판 번호 = 주문번호 끝 4자리
        $o = Order::where('order_no', 'arena1-1794797455-1882')->firstOrFail();
        $this->assertSame(14800, $o->total_amount);
        $this->assertSame('pos', $o->channel);
        $this->assertSame('pos_card', $o->pay_method);
        $this->assertSame('1882', $o->pickup_code);
        $this->assertSame('2026-11-16 11:50:55', $o->paid_at->timezone('Asia/Seoul')->format('Y-m-d H:i:s'));
        $this->assertSame(5, $o->items()->count());
        $this->assertSame(0, $o->items()->where('stock_deducted', true)->count());   // 재고를 다시 빼지 않는다

        // 원본에서 지워진 상품은 숨김 상품으로 남겨 판매 기록을 지킨다
        $chai = Product::where('legacy_ref', 'dadamda:469')->firstOrFail();
        $this->assertFalse($chai->is_visible);

        // 옵션: '/ ICE' 는 상품 옵션 ICE 와 연결
        $ice = Order::where('imported_from', 'dadamda')->whereHas('items', fn ($q) => $q->where('option_name', 'ICE'))->firstOrFail()->items()->where('option_name', 'ICE')->firstOrFail();
        $this->assertNotNull($ice->product_option_id);
    }

    public function test_running_again_skips_existing_orders(): void
    {
        $this->artisan('dadamda:import-orders', ['--store' => 'dobonpang'])->assertSuccessful();
        $this->artisan('dadamda:import-orders', ['--store' => 'dobonpang'])
            ->expectsOutputToContain('주문 0건 · 0원 가져옴 · 이미 있음 401건')
            ->assertSuccessful();
        $this->assertSame(401, Order::count());
    }

    public function test_imported_orders_stay_out_of_new_settlements(): void
    {
        $this->artisan('dadamda:import-orders', ['--store' => 'dobonpang'])->assertSuccessful();
        $this->assertSame(10, Order::where('status', OrderStatus::PickedUp->value)->count());   // 원본 수령완료(정산 전)

        app(SettlementService::class)->generate(now()->subYears(2), now());

        $this->assertSame(0, Settlement::count());
        $this->assertSame(0, Order::whereNotNull('settlement_id')->count());
    }

    public function test_needs_store_with_products(): void
    {
        $this->artisan('dadamda:import-orders', ['--store' => 'nope'])
            ->expectsOutputToContain('먼저 dadamda:import-products')
            ->assertFailed();
    }
}
