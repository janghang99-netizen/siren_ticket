<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\CapacitySlot;
use App\Models\Event;
use App\Models\Order;
use App\Models\Product;
use App\Models\Settlement;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OrderException;
use App\Services\OrderService;
use App\Services\SettlementService;
use App\Services\TicketService;
use App\Services\VenueSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 슈퍼어드민(공연·티켓·업체·정산) / 입점업체(여러 매장·상품·내역) */
class BackofficeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Vendor $vendor;
    private User $vendorUser;
    private Store $cafe;
    private Store $bakery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'super_admin']);
        $this->vendor = Vendor::create(['name' => '아레나푸드', 'commission_rate' => 10, 'state' => 'approved']);
        $this->vendorUser = User::factory()->create(['role' => 'vendor', 'vendor_id' => $this->vendor->id]);
        $this->cafe = Store::create(['vendor_id' => $this->vendor->id, 'code' => 'cafe', 'name' => '아레나 커피', 'commission_rate' => 10]);
        $this->bakery = Store::create(['vendor_id' => $this->vendor->id, 'code' => 'bakery', 'name' => '오븐 베이커리', 'commission_rate' => 10]);
    }

    private function pickedUpOrder(Store $store, int $price): Order
    {
        $p = Product::create(['store_id' => $store->id, 'name' => '메뉴'.$price, 'price' => $price]);
        $svc = app(OrderService::class);
        $o = $svc->confirmPayment($svc->place($store, [['product_id' => $p->id, 'qty' => 1]], 'card'));
        foreach ([OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::PickedUp] as $s) {
            $o = $svc->transition($o, $s);
        }

        return $o;
    }

    // ── 권한 ──

    public function test_roles_are_separated(): void
    {
        $this->actingAs($this->vendorUser)->get(route('admin.events.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('partner.board'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.events.index'))->assertOk();
        $this->actingAs($this->vendorUser)->get(route('partner.board'))->assertOk();
    }

    public function test_suspended_vendor_cannot_use_console(): void
    {
        $this->vendor->update(['state' => 'suspended']);
        $this->actingAs($this->vendorUser)->get(route('partner.board'))->assertForbidden();
    }

    public function test_login_sends_each_role_to_its_console(): void
    {
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'password'])->assertRedirect(route('admin.events.index'));
        $this->post('/logout');
        $this->post('/login', ['email' => $this->vendorUser->email, 'password' => 'password'])->assertRedirect(route('partner.board'));
    }

    public function test_logged_in_admin_can_switch_to_vendor_account(): void
    {
        // 운영사로 로그인한 채 로그인 화면을 열면 안내가 나오고, 업체 계정으로 바꿀 수 있다
        $this->actingAs($this->admin, 'web')->get('/login')->assertOk()->assertSee($this->admin->email)->assertSee('운영사');

        // 비밀번호가 틀리면 지금 로그인은 그대로
        $this->post('/login', ['email' => $this->vendorUser->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertAuthenticatedAs($this->admin, 'web');

        $this->post('/login', ['email' => $this->vendorUser->email, 'password' => 'password'])->assertRedirect(route('partner.board'));
        $this->assertAuthenticatedAs($this->vendorUser, 'web');
        $this->get(route('partner.board'))->assertOk();
    }

    // ── 한 업체 여러 매장 ──

    public function test_vendor_switches_between_own_stores_but_not_others(): void
    {
        $other = Store::create(['vendor_id' => Vendor::create(['name' => '남의업체', 'state' => 'approved'])->id, 'code' => 'x', 'name' => '남의 매장']);

        $this->actingAs($this->vendorUser)->post(route('partner.stores.switch', $this->bakery->id))->assertRedirect();
        $this->get(route('partner.products'))->assertSee('오븐 베이커리');

        $this->post(route('partner.stores.switch', $other->id))->assertForbidden();
    }

    public function test_vendor_registers_product_with_options_on_current_store(): void
    {
        $this->actingAs($this->vendorUser)->post(route('partner.stores.switch', $this->bakery->id));

        $this->post(route('partner.products.store'), [
            'name' => '소금빵', 'price' => 4000, 'stock_deduct_at' => 'order', 'is_visible' => '1', 'agree_prohibited' => '1',
            'options' => [['name' => '1개', 'extra_price' => 0, 'stock' => 50], ['name' => '2개 세트', 'extra_price' => 3500, 'stock' => 20]],
        ])->assertRedirect(route('partner.products'));

        $p = Product::where('name', '소금빵')->firstOrFail();
        $this->assertSame($this->bakery->id, $p->store_id);
        $this->assertSame(['1개', '2개 세트'], $p->options()->orderBy('id')->pluck('name')->all());
    }

    public function test_sales_and_cancel_history_cover_all_vendor_stores(): void
    {
        $this->pickedUpOrder($this->cafe, 4500);
        $this->pickedUpOrder($this->bakery, 9500);
        $svc = app(OrderService::class);
        $p = Product::create(['store_id' => $this->cafe->id, 'name' => '취소될 메뉴', 'price' => 3000]);
        $svc->transition($svc->confirmPayment($svc->place($this->cafe, [['product_id' => $p->id, 'qty' => 1]], 'card')), OrderStatus::Cancelled);

        $this->actingAs($this->vendorUser)->get(route('partner.sales'))->assertOk()->assertViewHas('total', 14000)->assertViewHas('count', 2);
        $this->get(route('partner.cancels'))->assertOk()->assertViewHas('count', 1)->assertSee('매장 취소');
    }

    // ── 정산 ──

    public function test_settlement_groups_picked_up_orders_per_vendor_once(): void
    {
        $a = $this->pickedUpOrder($this->cafe, 4500);
        $b = $this->pickedUpOrder($this->bakery, 9500);

        $made = app(SettlementService::class)->generate(now()->subDay(), now());

        $this->assertCount(1, $made);
        $s = $made->first();
        $this->assertSame(14000, $s->gross_amount);
        $this->assertSame(1400, $s->commission_amount);   // 10%
        $this->assertSame(140, $s->commission_vat);       // 수수료 부가세 10%
        $this->assertSame(12460, $s->payout_amount);      // 14000 - 1400 - 140
        $this->assertSame(OrderStatus::Settled, $a->fresh()->status);
        $this->assertSame($s->id, $b->fresh()->settlement_id);

        // 같은 기간 다시 돌려도 중복 정산 없음
        $this->assertCount(0, app(SettlementService::class)->generate(now()->subDay(), now()));

        app(SettlementService::class)->markPaid($s);
        $this->assertSame('paid', $s->fresh()->status);
        $this->expectException(OrderException::class);
        app(SettlementService::class)->markPaid($s->fresh());
    }

    public function test_admin_generates_settlement_and_vendor_sees_it(): void
    {
        $this->pickedUpOrder($this->cafe, 5000);

        $this->actingAs($this->admin)->post(route('admin.settlements.generate'), ['from' => now()->toDateString(), 'to' => now()->toDateString()])
            ->assertSessionHas('status');
        $settlement = Settlement::firstOrFail();

        $this->actingAs($this->vendorUser)->get(route('partner.settlements'))->assertOk()->assertSee(number_format(4450));
        $this->get(route('partner.settlements.show', $settlement))->assertOk();
    }

    // ── 공연 · 티켓 (슈퍼어드민) ──

    public function test_admin_registers_event_with_sections_and_store_slots(): void
    {
        $this->actingAs($this->admin)->post(route('admin.events.store'), [
            'name' => '테스트 투어', 'artist' => '테스트그룹', 'venue' => '서울아레나',
            'starts_at' => now()->addDays(20)->format('Y-m-d\TH:i'),
            'sales_open_at' => now()->format('Y-m-d\TH:i'),
            'theme_color' => '#123456', 'max_per_buyer' => 2,
            'tiers' => ['VIP' => ['price' => 200000, 'capacity' => 100], 'R' => ['price' => 150000, 'capacity' => 200], 'S' => ['price' => 100000, 'capacity' => 300]],
        ])->assertRedirect(route('admin.events.index'));

        $event = Event::where('artist', '테스트그룹')->firstOrFail();
        $this->assertSame(17, $event->sections()->count());
        $this->assertSame(3 * 100 + 6 * 200 + 8 * 300, (int) $event->sections()->sum('capacity'));
        $this->assertSame(2 * 3, CapacitySlot::where('event_id', $event->id)->count());   // 매장 2 × 시간대 3
    }

    public function test_admin_ticket_cancel_returns_seat_and_new_seat_numbers_do_not_collide(): void
    {
        $event = Event::create(['slug' => 's', 'name' => '공연', 'artist' => 'A', 'starts_at' => now()->addDay(), 'max_per_buyer' => 4]);
        app(VenueSetup::class)->createSections($event, ['VIP' => ['price' => 1, 'capacity' => 10], 'R' => ['price' => 1, 'capacity' => 10], 'S' => ['price' => 1, 'capacity' => 10]]);
        $section = $event->sections()->first();
        $tickets = app(TicketService::class)->purchase($event, $section, 2, '팬', '01011112222');

        $this->actingAs($this->admin)->patch(route('admin.tickets.cancel', $tickets->first()))->assertRedirect();
        $this->assertSame('cancelled', $tickets->first()->fresh()->status);
        $this->assertSame(1, $section->fresh()->sold);

        $again = app(TicketService::class)->purchase($event, $section->fresh(), 1, '다른팬', '01033334444');
        $this->assertSame(3, $again->first()->seat_no);
        $this->assertSame(3, Ticket::where('seat_section_id', $section->id)->distinct()->count('seat_no'));
    }
}
