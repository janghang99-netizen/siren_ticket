<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 공연 없이 상시 매장 주문 (원본 다담다 기본 동작) */
class AlwaysOpenShopTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $coffee;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put(['ticketing_enabled' => '0']);
        $vendor = Vendor::create(['name' => '도본팡', 'state' => 'approved']);
        $this->store = Store::create(['vendor_id' => $vendor->id, 'code' => 'arena1', 'name' => 'Do BON PAIN',
            'area' => '쌍문동', 'address' => '서울 도봉구 노해로60길 65 1층', 'hours' => '평일 11:00~20:00', 'holidays' => '매주 토·일']);
        $this->coffee = Product::create(['store_id' => $this->store->id, 'name' => '아메리카노', 'price' => 4500, 'stock' => 100]);
    }

    public function test_home_and_store_list_work_without_any_event(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('먼저 주문하고')->assertSee('Do BON PAIN')->assertSee(route('shop.menu', $this->store));
        $this->get(route('order.entry'))->assertRedirect(route('shop.index'));
        $this->get(route('shop.index'))->assertOk()->assertSee('전체 매장')->assertSee('쌍문동')->assertSee('주문 가능');
        $this->get(route('shop.menu', $this->store))->assertOk()
            ->assertSee('Do BON PAIN')->assertSee('노해로60길')->assertSee('평일 11:00~20:00')->assertDontSee('언제 받으세요?');
    }

    public function test_order_without_event_or_slot(): void
    {
        $this->postJson(route('shop.order', $this->store), [
            'agree_terms' => true, 'items' => [['product_id' => $this->coffee->id, 'qty' => 2]],
        ])->assertOk()->assertJsonPath('result', 'ok');

        $order = Order::firstOrFail();
        $this->assertNull($order->capacity_slot_id);
        $this->assertSame(9000, (int) $order->total_amount);
    }

    public function test_closed_store_shows_preparing_and_refuses_orders(): void
    {
        $this->store->update(['is_open' => false]);
        $this->get(route('shop.index'))->assertSee('준비중');
        $this->postJson(route('shop.order', $this->store), [
            'agree_terms' => true, 'items' => [['product_id' => $this->coffee->id, 'qty' => 1]],
        ])->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_brand_business_info_and_pickup_board_qr(): void
    {
        // 화면 이름 기본값 'SIREN Ticket' + 하단 사업자 정보 (통신판매중개자 고지)
        $home = $this->get(route('home'))->assertOk();
        $home->assertSee('<title>홈 · SIREN Ticket</title>', false)->assertSee('SIREN Ticket 매장 주문')->assertDontSee('다담다')
            ->assertSee('231-88-02240')->assertSee('2021-서울서초-3972')->assertSee('통신판매중개자');

        // 전광판: 이 매장 상시 주문 QR
        $this->get(route('display', $this->store))->assertOk()
            ->assertSee('id="order-qr"', false)->assertSee(route('shop.menu', $this->store))->assertSee('찍고 주문');

        // 운영사가 사업자 정보를 고치면 바로 반영
        $admin = \App\Models\User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->put(route('admin.settings.site'), [
            'brand_name' => '다담다', 'company_name' => '주식회사 태라글로벌', 'company_phone' => '02-000-0000', 'company_email' => 'help@example.com', 'company_info_visible' => '1',
        ])->assertSessionHasNoErrors();
        $this->get(route('home'))->assertSee('02-000-0000')->assertSee('help@example.com');
    }

    public function test_unapproved_vendor_store_is_hidden(): void
    {
        $other = Store::create(['vendor_id' => Vendor::create(['name' => '심사중', 'state' => 'pending'])->id, 'code' => 'wait1', 'name' => '심사중 매장']);
        $this->get(route('shop.index'))->assertDontSee('심사중 매장');
        $this->get(route('shop.menu', $other))->assertNotFound();
    }
}
