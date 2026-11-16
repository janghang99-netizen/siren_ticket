<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\CapacitySlot;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Benefits\SmsSender;
use App\Services\OrderException;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 매장 운영 보강: 같은 매장 묶음결제 · 보드에서 품절 · 카테고리 · 준비 완료 문자 · 이미지 축소 */
class StoreOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;
    private Store $store;
    private Store $other;
    private Product $coffee;
    private Product $cake;
    private CapacitySlot $slot;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendor = Vendor::create(['name' => '아레나푸드', 'state' => 'approved']);
        $this->store = Store::create(['vendor_id' => $this->vendor->id, 'code' => 'arena1', 'name' => '아레나 커피', 'commission_rate' => 10]);
        $this->other = Store::create(['vendor_id' => $this->vendor->id, 'code' => 'bake1', 'name' => '오븐 베이커리', 'commission_rate' => 10]);
        $coffeeCat = \App\Models\ProductCategory::create(['store_id' => $this->store->id, 'name' => '커피', 'sort' => 1]);
        $this->coffee = Product::create(['store_id' => $this->store->id, 'category_id' => $coffeeCat->id, 'name' => '아메리카노', 'price' => 4500, 'stock' => 10]);
        $this->cake = Product::create(['store_id' => $this->other->id, 'name' => '크루아상', 'price' => 4000, 'stock' => 10]);
        $event = Event::create(['slug' => 's', 'name' => '공연', 'artist' => 'A', 'starts_at' => now()->addDay(), 'sales_open_at' => now()->subHour(), 'max_per_buyer' => 4]);
        $this->slot = CapacitySlot::create(['event_id' => $event->id, 'store_id' => $this->store->id, 'slot' => 'pre', 'capacity' => 50]);
        $this->staff = User::factory()->create(['role' => 'vendor', 'vendor_id' => $this->vendor->id]);
    }

    public function test_home_shows_stores_and_menu_and_order_tab_goes_to_stores(): void
    {
        $event = Event::first();
        $this->get(route('home'))->assertOk()
            ->assertSee('줄 서지 말고, 미리 주문')
            ->assertSee('아레나 커피')                                   // 주문 받는 매장 (슬롯 있음)
            ->assertDontSee('오븐 베이커리')                             // 이 공연 슬롯이 없는 매장은 안 보임
            ->assertSee('아메리카노')
            ->assertSee(route('stores.menu', [$event, $this->store]), false)
            ->assertSee(route('order.entry'), false);                   // 하단 '매장주문' 탭

        $this->get(route('order.entry'))->assertRedirect(route('stores.index', $event));

        $event->update(['status' => 'cancelled']);
        $this->get(route('order.entry'))->assertRedirect(route('home'));
    }

    public function test_demo_product_images_attach_once(): void
    {
        Storage::fake('local');
        $this->assertSame(2, \Database\Seeders\DemoProductImages::fillMissing());   // 아메리카노 · 크루아상
        $path = $this->coffee->fresh()->image_path;
        Storage::disk('local')->assertExists($path);
        $this->get(route('media.product', basename($path)))->assertOk();
        $this->assertSame(0, \Database\Seeders\DemoProductImages::fillMissing());   // 이미 있으면 다시 안 붙임
    }

    public function test_bundle_payment_only_within_same_store(): void
    {
        $svc = app(OrderService::class);
        try {
            $svc->place($this->store, [['product_id' => $this->coffee->id, 'qty' => 1], ['product_id' => $this->cake->id, 'qty' => 1]], 'card', $this->slot->id);
            $this->fail('다른 매장 상품이 섞이면 막아야 함');
        } catch (OrderException $e) {
            $this->assertSame('mixed_store', $e->reason);
            $this->assertStringContainsString('같은 매장', $e->getMessage());
        }
        // 같은 매장 상품 여러 개는 한 번에
        $latte = Product::create(['store_id' => $this->store->id, 'name' => '라떼', 'price' => 5000, 'stock' => 10]);
        $order = $svc->place($this->store, [['product_id' => $this->coffee->id, 'qty' => 1], ['product_id' => $latte->id, 'qty' => 2]], 'card', $this->slot->id);
        $this->assertCount(2, $order->items);
    }

    public function test_board_sold_out_toggle_blocks_orders_and_shows_on_board(): void
    {
        $this->actingAs($this->staff, 'web')->patchJson(route('partner.products.sold-out', $this->coffee))->assertOk()->assertJsonPath('sold_out', true);
        $this->assertTrue($this->coffee->fresh()->isSoldOut());
        $this->assertSame(10, $this->coffee->fresh()->stock);   // 재고 숫자는 그대로

        try {
            app(OrderService::class)->place($this->store, [['product_id' => $this->coffee->id, 'qty' => 1]], 'card', $this->slot->id);
            $this->fail('품절이면 막아야 함');
        } catch (OrderException $e) {
            $this->assertSame('sold_out', $e->reason);
        }

        $this->getJson(route('partner.board.data'))->assertOk()
            ->assertJsonPath('products.0.name', '아메리카노')->assertJsonPath('products.0.sold_out', true);

        $this->patchJson(route('partner.products.sold-out', $this->coffee))->assertJsonPath('sold_out', false);
        $this->assertFalse($this->coffee->fresh()->isSoldOut());

        // 다른 업체 상품은 못 바꾼다
        $strangerStore = Store::create(['vendor_id' => Vendor::create(['name' => '남', 'state' => 'approved'])->id, 'code' => 'x', 'name' => '남의 매장']);
        $stranger = Product::create(['store_id' => $strangerStore->id, 'name' => '남의 상품', 'price' => 1000]);
        $this->patchJson(route('partner.products.sold-out', $stranger))->assertForbidden();
    }

    public function test_category_management_and_product_assignment(): void
    {
        $this->actingAs($this->staff, 'web')->post(route('partner.stores.switch', $this->store->id));
        $coffee = $this->coffee->category;
        $base = ['price' => 3000, 'stock_deduct_at' => 'order', 'is_visible' => 1, 'agree_prohibited' => 1];

        // 폼: 이 매장 카테고리 선택 상자
        $this->get(route('partner.products.create'))->assertOk()->assertSee('<option value="'.$coffee->id.'"', false);
        // 새 카테고리를 상품 폼에서 바로 만들기 (공백 정리)
        $this->post(route('partner.products.store'), $base + ['name' => '아이스티', 'new_category' => ' 음료 '])->assertRedirect();
        $tea = Product::where('name', '아이스티')->first();
        $this->assertSame('음료', $tea->category->name);
        $this->assertSame(2, $tea->category->sort);   // 맨 뒤에 붙는다
        // 다른 매장 카테고리는 고를 수 없다
        $foreign = \App\Models\ProductCategory::create(['store_id' => $this->other->id, 'name' => '빵']);
        $this->post(route('partner.products.store'), $base + ['name' => '남의 것', 'category_id' => $foreign->id])->assertSessionHasErrors('category_id');

        // 관리: 추가 · 중복 이름 막기 · 순서 · 이름 바꾸기
        $this->post(route('partner.categories.store'), ['name' => '디저트'])->assertSessionHas('status');
        $dessert = \App\Models\ProductCategory::where('name', '디저트')->first();
        $this->put(route('partner.categories.update', $dessert), ['name' => '커피', 'is_visible' => 1])->assertSessionHasErrors('name');
        $this->patch(route('partner.categories.move', $dessert), ['dir' => 'up']);
        $this->assertSame(['커피', '디저트', '음료'], \App\Models\ProductCategory::where('store_id', $this->store->id)->orderBy('sort')->pluck('name')->all());
        $this->put(route('partner.categories.update', $coffee), ['name' => '에스프레소 바', 'is_visible' => 1]);
        $this->assertSame('에스프레소 바', $this->coffee->fresh()->category->name);   // 상품에 바로 반영

        // 상품 목록: 카테고리별로 묶이고 거를 수 있다
        $this->get(route('partner.products'))->assertOk()->assertSeeInOrder(['에스프레소 바', '아메리카노', '음료', '아이스티']);
        $this->get(route('partner.products', ['cat' => $tea->category_id]))->assertSee('아이스티')->assertDontSee('<a href="'.route('partner.products.edit', $this->coffee).'" class="row-link">', false);

        // 숨기면 손님 메뉴·현장판매·주문에서 빠진다
        $this->put(route('partner.categories.update', $coffee), ['name' => '에스프레소 바']);   // is_visible 없음 = 숨김
        $this->assertFalse(Product::sellable()->whereKey($this->coffee->id)->exists());
        $this->get(route('partner.pos.screen'))->assertViewHas('menu', fn ($m) => ! $m->pluck('name')->contains('아메리카노') && $m->pluck('name')->contains('아이스티'));
        try {
            app(OrderService::class)->place($this->store, [['product_id' => $this->coffee->id, 'qty' => 1]], 'card', $this->slot->id);
            $this->fail('숨긴 카테고리 상품은 주문할 수 없어야 함');
        } catch (OrderException $e) {
            $this->assertStringContainsString('판매하지 않는', $e->getMessage());
        }

        // 삭제해도 상품은 남고 '기타' 로
        $this->delete(route('partner.categories.destroy', $coffee))->assertSessionHas('status');
        $this->assertNull($this->coffee->fresh()->category_id);
        $this->assertSame('기타', $this->coffee->fresh()->categoryName());

        // 현장판매: '전체' 탭 + 카테고리 순서 (기타는 맨 뒤)
        $this->get(route('partner.pos.screen'))->assertViewHas('menu', fn ($m) => $m->pluck('cat')->all() === ['음료', '기타']);
    }

    public function test_ready_sends_one_sms_to_customer(): void
    {
        Http::fake();
        $sms = new class implements SmsSender
        {
            public array $sent = [];

            public function send(string $phone, string $message): void
            {
                $this->sent[] = [$phone, $message];
            }
        };
        $this->app->instance(SmsSender::class, $sms);
        $svc = app(OrderService::class);
        $order = $svc->confirmPayment($svc->place($this->store, [['product_id' => $this->coffee->id, 'qty' => 1]], 'card', $this->slot->id,
            customer: Customer::forPhone('010-1234-5678')));

        foreach ([OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::Preparing, OrderStatus::Ready] as $s) {   // 되돌렸다 다시 준비 완료
            $order = $svc->transition($order, $s);
        }
        $this->assertCount(1, $sms->sent);
        $this->assertSame('01012345678', $sms->sent[0][0]);
        $this->assertStringContainsString($order->pickup_code, $sms->sent[0][1]);
        $this->assertStringContainsString('아레나 커피', $sms->sent[0][1]);

        // 번호를 모르는 주문은 보내지 않는다
        $anon = $svc->confirmPayment($svc->place($this->store, [['product_id' => $this->coffee->id, 'qty' => 1]], 'card', $this->slot->id));
        $svc->transition($svc->transition($anon, OrderStatus::Preparing), OrderStatus::Ready);
        $this->assertCount(1, $sms->sent);
    }

    public function test_pos_screen_sells_and_prints_number_ticket(): void
    {
        $this->actingAs($this->staff, 'web')->post(route('partner.stores.switch', $this->store->id));
        $terminal = \Illuminate\Support\Facades\DB::table('store_terminals')->insertGetId(['store_id' => $this->store->id, 'provider' => 'KSNET', 'tid' => 'T-001', 'label' => '1번 카운터', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->get(route('partner.pos.screen'))->assertOk()->assertSee('현장판매')
            ->assertViewHas('menu', fn ($m) => $m->pluck('name')->contains('아메리카노'))
            ->assertViewHas('terminals', fn ($t) => $t->pluck('label')->contains('1번 카운터'));

        // 카드: 단말기·승인번호 없으면 거절
        $this->postJson(route('partner.pos'), ['pay_method' => 'pos_card', 'items' => [['product_id' => $this->coffee->id, 'qty' => 1]]])->assertStatus(422);

        $res = $this->postJson(route('partner.pos'), [
            'pay_method' => 'pos_card', 'terminal_id' => $terminal, 'card_approval_no' => '30012345',
            'items' => [['product_id' => $this->coffee->id, 'qty' => 2, 'dine_option' => '매장']],
        ])->assertOk()->assertJsonStructure(['pickup_code', 'order_no', 'ticket_url']);
        $code = $res->json('pickup_code');

        // 손님 번호표: 큰 번호 · 매장 이름 · 품목 · 합계 · 승인번호
        $this->get($res->json('ticket_url'))->assertOk()
            ->assertSee($code)->assertSee('아레나 커피')->assertSee('아메리카노')->assertSee('9,000원')->assertSee('승인 30012345')
            ->assertSee('size: 80mm auto', false)->assertSee('매장');
        // 58mm · 재출력 표시 · 자동 인쇄
        $this->get($res->json('ticket_url').'?width=58&reprint=1&auto=1')->assertOk()
            ->assertSee('size: 58mm auto', false)->assertSee('재출력')->assertSee('window.print()', false);
        // 주방 주문서: 금액 없이 번호·품목
        $this->get($res->json('ticket_url').'?kind=kitchen')->assertOk()->assertSee('주방')->assertSee('×2')->assertDontSee('9,000원');

        // 오늘 현장판매 목록에 뜬다 (재인쇄)
        $this->get(route('partner.pos.screen'))->assertViewHas('recent', fn ($r) => $r->pluck('pickup_code')->contains($code));

        // 다른 업체는 번호표를 볼 수 없다
        $strangerVendor = Vendor::create(['name' => '남', 'state' => 'approved']);
        Store::create(['vendor_id' => $strangerVendor->id, 'code' => 'y', 'name' => '남의 매장']);
        $stranger = User::factory()->create(['role' => 'vendor', 'vendor_id' => $strangerVendor->id]);
        $this->actingAs($stranger, 'web')->get($res->json('ticket_url'))->assertForbidden();
    }

    public function test_vendor_manages_terminals_for_every_own_store(): void
    {
        $this->actingAs($this->staff, 'web');
        // 업체의 매장 모두가 한 화면에
        $this->get(route('partner.terminals'))->assertOk()->assertSee('아레나 커피')->assertSee('오븐 베이커리')->assertSee('카드 결제 불가');

        foreach ([$this->store, $this->other] as $i => $s) {
            $this->post(route('partner.terminals.store'), ['store_id' => $s->id, 'provider' => 'ksnet', 'tid' => 'T-00'.$i, 'label' => '1번 카운터'])->assertSessionHas('status');
        }
        $t = \App\Models\StoreTerminal::where('tid', 'T-000')->first();
        $this->assertSame('KSNET', $t->provider);   // 대문자로 정리
        $this->assertSame(2, \App\Models\StoreTerminal::count());

        // TID 중복 · 이상한 문자 거절
        $this->post(route('partner.terminals.store'), ['store_id' => $this->store->id, 'provider' => 'NICE', 'tid' => 'T-000'])->assertSessionHasErrors('tid');
        $this->post(route('partner.terminals.store'), ['store_id' => $this->store->id, 'provider' => 'NICE', 'tid' => 'T 00 1'])->assertSessionHasErrors('tid');

        // 수정 · 사용 중지 → 현장판매에서 빠짐 · 다시 사용
        $this->put(route('partner.terminals.update', $t), ['provider' => 'NICE', 'label' => '2번 카운터']);
        $this->assertSame(['NICE', '2번 카운터'], [$t->fresh()->provider, $t->fresh()->label]);
        $this->post(route('partner.stores.switch', $this->store->id));
        $this->patch(route('partner.terminals.toggle', $t));
        $this->get(route('partner.pos.screen'))->assertViewHas('terminals', fn ($list) => $list->isEmpty());
        $this->postJson(route('partner.pos'), ['pay_method' => 'pos_card', 'terminal_id' => $t->id, 'card_approval_no' => '1', 'items' => [['product_id' => $this->coffee->id, 'qty' => 1]]])->assertStatus(422);
        $this->patch(route('partner.terminals.toggle', $t));
        $this->get(route('partner.pos.screen'))->assertViewHas('terminals', fn ($list) => $list->pluck('tid')->all() === ['T-000']);

        // 결제 기록이 있으면 삭제 대신 사용 중지
        $this->postJson(route('partner.pos'), ['pay_method' => 'pos_card', 'terminal_id' => $t->id, 'card_approval_no' => '777', 'items' => [['product_id' => $this->coffee->id, 'qty' => 1]]])->assertOk();
        $this->delete(route('partner.terminals.destroy', $t))->assertSessionHas('status');
        $this->assertFalse($t->fresh()->is_active);
        // 기록 없는 단말기는 삭제
        $other = \App\Models\StoreTerminal::where('tid', 'T-001')->first();
        $this->delete(route('partner.terminals.destroy', $other));
        $this->assertNull($other->fresh());
        $this->assertDatabaseHas('audit_logs', ['action' => 'terminal.created']);

        // 남의 매장에는 등록·수정 불가
        $strangerStore = Store::create(['vendor_id' => Vendor::create(['name' => '남', 'state' => 'approved'])->id, 'code' => 'z', 'name' => '남의 매장']);
        $this->post(route('partner.terminals.store'), ['store_id' => $strangerStore->id, 'provider' => 'KIS', 'tid' => 'X-1'])->assertForbidden();
        $foreign = \App\Models\StoreTerminal::create(['store_id' => $strangerStore->id, 'provider' => 'KIS', 'tid' => 'X-2']);
        $this->patch(route('partner.terminals.toggle', $foreign))->assertForbidden();

        // 운영사는 어느 매장이든
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'web')->post(route('admin.stores.terminals', $strangerStore->id), ['provider' => 'KIS', 'tid' => 'X-3'])->assertSessionHas('status');
        $this->patch(route('admin.terminals.toggle', $foreign->id))->assertSessionHas('status');
        $this->assertFalse($foreign->fresh()->is_active);
    }

    public function test_uploaded_image_is_resized_to_webp(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD webp 없음');
        }
        Storage::fake('local');
        $this->actingAs($this->staff, 'web')->put(route('partner.products.update', $this->coffee), [
            'name' => '아메리카노', 'price' => 4500, 'stock_deduct_at' => 'order', 'is_visible' => 1,
            'image' => UploadedFile::fake()->image('big.jpg', 3000, 2000),
        ])->assertRedirect();

        $path = $this->coffee->fresh()->image_path;
        $this->assertStringEndsWith('.webp', $path);
        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame([1000, 667], [$w, $h]);   // 긴 변 1000px, 비율 유지
    }
}
