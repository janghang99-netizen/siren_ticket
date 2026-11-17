<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\EisService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 원본 다담다 EIS 연동 — 업체 단위 (주문 push · 생산 push · 판매/재고 pull) */
class EisIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;
    private Store $cafe;
    private Store $bakery;
    private Product $bread;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->vendor = Vendor::create(['name' => '아레나푸드', 'state' => 'approved']);
        $this->cafe = Store::create(['vendor_id' => $this->vendor->id, 'code' => 'arena1', 'name' => '커피']);
        $this->bakery = Store::create(['vendor_id' => $this->vendor->id, 'code' => 'arena3', 'name' => '베이커리']);
        $this->bread = Product::create(['store_id' => $this->bakery->id, 'name' => '소금빵', 'price' => 4000, 'stock' => 50]);
        DB::table('vendor_integrations')->insert(['vendor_id' => $this->vendor->id, 'eis_code' => 'arenafood', 'order_url' => 'https://eis.test/order',
            'production_url' => 'https://eis.test/production', 'secret' => 'k3y', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_production_overwrites_stock_and_pushes_signed_event_to_vendor_eis(): void
    {
        $id = app(EisService::class)->recordProduction($this->bread, null, 120, '오전', null);
        $this->assertSame(120, $this->bread->fresh()->stock);   // 누적 아님: 50 → 120
        Http::assertSent(fn ($r) => $r->url() === 'https://eis.test/production'
            && $r->header('X-Dadamda-Event')[0] === 'production.created'
            && $r->header('X-Dadamda-Signature')[0] === 'sha256='.hash_hmac('sha256', $r->body(), 'k3y')
            && $r['productions'][0]['seller_id'] === 'arenafood' && $r['productions'][0]['store_code'] === 'arena3'
            && $r['productions'][0]['qty'] === 120 && $r['productions'][0]['production_date'] === now()->toDateString());

        app(EisService::class)->updateProduction($id, 100, null, null);
        app(EisService::class)->deleteProduction($id);
        $this->assertSame(100, $this->bread->fresh()->stock);
        $this->assertSame(['production.created', 'production.updated', 'production.deleted'], DB::table('integration_logs')->orderBy('id')->pluck('type')->all());
    }

    public function test_orders_from_any_vendor_store_push_to_vendor_order_url(): void
    {
        $svc = app(OrderService::class);
        $o = $svc->confirmPayment($svc->place($this->bakery, [['product_id' => $this->bread->id, 'qty' => 1]], 'card'));
        foreach ([OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::PickedUp] as $s) {
            $o = $svc->transition($o, $s);
        }
        Http::assertSent(fn ($r) => $r->url() === 'https://eis.test/order' && $r['orders'][0]['seller_id'] === 'arenafood' && $r['orders'][0]['store_code'] === 'arena3');
    }

    public function test_order_pull_requires_signature_and_returns_all_vendor_stores(): void
    {
        $svc = app(OrderService::class);
        $o = $svc->confirmPayment($svc->place($this->bakery, [['product_id' => $this->bread->id, 'qty' => 2]], 'card'));
        foreach ([OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::PickedUp] as $s) {
            $svc->transition($o, $s);
        }
        $body = json_encode(['seller_id' => 'arenafood', 'from' => now()->toDateString(), 'to' => now()->toDateString()]);

        $this->call('POST', '/api/eis/orders', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_DADAMDA_SIGNATURE' => 'sha256=bad'], $body)->assertStatus(401);
        $res = $this->call('POST', '/admin/openmarket_export.php', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_DADAMDA_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'k3y')], $body);
        $res->assertOk()->assertJsonPath('orders.0.store_code', 'arena3')->assertJsonPath('orders.0.qty', 2);
    }

    public function test_stock_pull_checks_time_window_and_signature(): void
    {
        $ts = time();
        $sig = hash_hmac('sha256', 'arenafood.'.$ts, 'k3y');
        $this->getJson("/api/stock_export.php?seller_id=arenafood&ts={$ts}&sig={$sig}")->assertOk()
            ->assertJsonPath('items.0.store_code', 'arena3')->assertJsonPath('items.0.current_stock', 50);

        $old = $ts - 301;
        $this->getJson('/api/eis/stock?seller_id=arenafood&ts='.$old.'&sig='.hash_hmac('sha256', 'arenafood.'.$old, 'k3y'))->assertStatus(401);
        $this->getJson("/api/eis/stock?seller_id=arenafood&ts={$ts}&sig=wrong")->assertStatus(401);
        $this->getJson("/api/eis/stock?seller_id=nobody&ts={$ts}&sig={$sig}")->assertStatus(404);
    }

    public function test_admin_saves_vendor_integration_and_vendor_records_production(): void
    {
        DB::table('vendor_integrations')->delete();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->put(route('admin.vendors.integration', $this->vendor), ['eis_code' => 'af01', 'order_url' => 'https://eis.test/o', 'is_active' => '1'])->assertRedirect();
        $this->assertSame(40, strlen(DB::table('vendor_integrations')->value('secret')));

        $user = User::factory()->create(['role' => 'vendor', 'vendor_id' => $this->vendor->id]);
        $this->actingAs($user)->post(route('partner.stores.switch', $this->bakery->id));
        $this->post(route('partner.production.save'), ['qty' => [$this->bread->id.':' => 77]])->assertSessionHas('status');
        $this->assertSame(77, $this->bread->fresh()->stock);
        $this->get(route('partner.production'))->assertOk()->assertSee('소금빵');
    }

    /** 생산량 입력 목록은 판매 중인 상품만 (판매중지 상품은 빠진다) */
    public function test_production_entry_lists_only_visible_products(): void
    {
        Product::create(['store_id' => $this->bakery->id, 'name' => '판매중지 바게트', 'price' => 3000, 'stock' => 0, 'is_visible' => false]);
        $user = User::factory()->create(['role' => 'vendor', 'vendor_id' => $this->vendor->id]);
        $this->actingAs($user)->post(route('partner.stores.switch', $this->bakery->id));

        $this->get(route('partner.production'))->assertOk()->assertSee('소금빵')->assertDontSee('판매중지 바게트');
    }
}
