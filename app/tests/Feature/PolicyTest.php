<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\Product;
use App\Models\SeatSection;
use App\Models\Settlement;
use App\Models\SettlementAdjustment;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Vendor;
use App\Services\InventoryService;
use App\Services\OrderException;
use App\Services\OrderService;
use App\Services\SettlementService;
use App\Services\TicketService;
use App\Services\VenueSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** docs/OPENMARKET_POLICY.md 항목별 검증 */
class PolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Vendor $vendor;
    private User $vendorUser;
    private Store $store;
    private Product $coffee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'super_admin']);
        $this->vendor = Vendor::create(['name' => '아레나푸드', 'state' => 'approved', 'commission_rate' => 10]);
        $this->vendorUser = User::factory()->create(['role' => 'vendor', 'vendor_id' => $this->vendor->id]);
        $this->store = Store::create(['vendor_id' => $this->vendor->id, 'code' => 'cafe', 'name' => '아레나 커피', 'commission_rate' => 10]);
        $this->coffee = Product::create(['store_id' => $this->store->id, 'name' => '아메리카노', 'price' => 5000, 'stock' => 10]);
    }

    private function order(?array $lines = null, bool $adult = false): Order
    {
        $svc = app(OrderService::class);

        return $svc->confirmPayment($svc->place($this->store, $lines ?? [['product_id' => $this->coffee->id, 'qty' => 1]], 'card', adultConfirmed: $adult));
    }

    private function pickUp(Order $o): Order
    {
        foreach ([OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::PickedUp] as $s) {
            $o = app(OrderService::class)->transition($o, $s);
        }

        return $o;
    }

    // ── A. 입점 심사 ──

    public function test_a1_application_creates_pending_vendor_with_private_documents(): void
    {
        Storage::fake('local');
        $file = fn () => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $this->post('/partner/apply', [
            'name' => '새업체', 'business_no' => '123-45-67890', 'mail_order_no' => '제2026-0001호', 'representative' => '홍길동',
            'address' => '서울', 'contact_phone' => '02-000-0000', 'contact_email' => 'a@test.dev',
            'bank_name' => '국민', 'bank_account' => '1', 'account_holder' => '새업체',
            'login_email' => 'new@test.dev', 'login_password' => 'secret123', 'login_password_confirmation' => 'secret123',
            'docs' => ['business_reg' => $file(), 'mail_order' => $file(), 'id_card' => $file(), 'bank_copy' => $file()],
            'agree_contract' => '1', 'agree_prohibited' => '1',
        ])->assertRedirect(route('partner.status'));

        $v = Vendor::where('name', '새업체')->firstOrFail();
        $this->assertSame('pending', $v->state);
        $this->assertCount(4, $v->documents);
        Storage::disk('local')->assertExists($v->documents['business_reg']);

        // 심사 중에는 콘솔 접근 불가, 상태 화면만
        $this->get(route('partner.board'))->assertForbidden();
        $this->get(route('partner.status'))->assertOk()->assertSee('입점 심사 중이에요');
    }

    public function test_a1_pending_vendor_cannot_sell_until_approved(): void
    {
        $this->vendor->update(['state' => 'pending']);
        $this->expectException(OrderException::class);
        $this->order();
    }

    public function test_a4_suspending_vendor_closes_all_its_stores(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.vendors.state', $this->vendor), ['state' => 'suspended', 'reason' => '주류 판매 위반'])->assertRedirect();
        $this->assertSame('suspended', $this->vendor->fresh()->state);
        $this->assertFalse($this->store->fresh()->is_open);

        $this->actingAs($this->admin)->patch(route('admin.vendors.state', $this->vendor), ['state' => 'suspended'])->assertSessionHasErrors('reason');
    }

    public function test_a2_store_menu_discloses_seller_identity_and_intermediary_notice(): void
    {
        $this->vendor->update(['business_no' => '123-45-67890', 'mail_order_no' => '제2026-0001호', 'representative' => '김하늘']);
        $event = Event::create(['slug' => 'e', 'name' => 'E', 'artist' => 'A', 'starts_at' => now()->addDay()]);
        app(VenueSetup::class)->ensureSlots($event, $this->store);

        $this->get(route('stores.menu', [$event, $this->store]))->assertOk()
            ->assertSee('123-45-67890')->assertSee('제2026-0001호')->assertSee('김하늘')
            ->assertSee('통신판매중개자');
    }

    // ── B. 상품 ──

    public function test_b1_admin_block_hides_product_and_blocks_orders(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.products.block', $this->coffee), ['reason' => '허위 표시'])->assertRedirect();
        $this->assertTrue($this->coffee->fresh()->isBlocked());

        $this->expectException(OrderException::class);
        $this->order();
    }

    public function test_b2_alcohol_needs_adult_confirmation_and_flags_id_check(): void
    {
        $beer = Product::create(['store_id' => $this->store->id, 'name' => '맥주', 'price' => 9000, 'is_alcohol' => true]);

        try {
            $this->order([['product_id' => $beer->id, 'qty' => 1]]);
            $this->fail('성인 확인 없이 주류 주문됨');
        } catch (OrderException $e) {
            $this->assertSame('adult_required', $e->reason);
        }

        $o = $this->order([['product_id' => $beer->id, 'qty' => 1]], adult: true);
        $this->assertTrue($o->requires_id_check);
        $this->assertNotNull($o->adult_confirmed_at);
    }

    public function test_b2_only_licensed_vendor_can_register_alcohol(): void
    {
        $payload = ['name' => '맥주', 'price' => 9000, 'stock_deduct_at' => 'order', 'is_alcohol' => '1', 'agree_prohibited' => '1'];
        $this->actingAs($this->vendorUser)->post(route('partner.products.store'), $payload)->assertSessionHasErrors('is_alcohol');

        $this->vendor->update(['liquor_license_no' => '제403-1호']);
        $this->actingAs($this->vendorUser->fresh())->post(route('partner.products.store'), $payload)->assertRedirect(route('partner.products'));
        $this->assertTrue(Product::where('name', '맥주')->first()->is_alcohol);
    }

    // ── C. 정산 ──

    public function test_c1_settlement_separates_commission_vat(): void
    {
        $this->pickUp($this->order());   // 5000원, 수수료 10% = 500, 부가세 50
        $s = app(SettlementService::class)->generate(now(), now())->first();

        $this->assertSame(500, $s->commission_amount);
        $this->assertSame(50, $s->commission_vat);
        $this->assertSame(4450, $s->payout_amount);
        $this->assertSame(now()->addDays(3)->toDateString(), $s->payout_due_on->toDateString());
    }

    public function test_c6_refund_after_settlement_is_deducted_next_time_with_carry_over(): void
    {
        $first = $this->pickUp($this->order());
        app(SettlementService::class)->generate(now(), now());

        $this->actingAs($this->admin)->post(route('admin.orders.refund', $first->fresh()), ['reason' => '하자'])->assertRedirect();
        $this->assertSame(OrderStatus::Refunded, $first->fresh()->status);
        $adj = SettlementAdjustment::firstOrFail();
        $this->assertSame(4450, $adj->amount);          // 업체가 받았던 정산금

        // 다음 정산: 5000 - 500 - 50 - 4450 = 0
        $this->pickUp($this->order());
        $next = app(SettlementService::class)->generate(now(), now())->first();
        $this->assertSame(4450, $next->deduction_amount);
        $this->assertSame(0, $next->payout_amount);
        $this->assertSame($next->id, $adj->fresh()->settlement_id);
    }

    public function test_c3_auto_settlement_runs_only_on_vendor_settlement_day(): void
    {
        $this->vendor->update(['settlement_cycle' => 'weekly', 'settlement_day' => 1]);   // 매주 월요일
        Carbon::setTestNow(Carbon::parse('2026-11-11 12:00'));   // 수요일
        $this->pickUp($this->order());

        $this->assertCount(0, app(SettlementService::class)->runDue(Carbon::parse('2026-11-14')));   // 토요일
        $made = app(SettlementService::class)->runDue(Carbon::parse('2026-11-16'));                 // 월요일
        $this->assertCount(1, $made);
        $this->assertSame('2026-11-15', $made->first()->period_end->toDateString());
        Carbon::setTestNow();
    }

    public function test_c8_dispute_within_30_days_and_admin_reply(): void
    {
        $this->pickUp($this->order());
        $s = app(SettlementService::class)->generate(now(), now())->first();

        $this->actingAs($this->vendorUser)->post(route('partner.settlements.dispute', $s), ['message' => '수수료율이 달라요'])->assertRedirect();
        $this->assertNotNull($s->fresh()->disputed_at);
        $this->actingAs($this->admin)->post(route('admin.settlements.reply', $s), ['reply' => '확인했어요'])->assertRedirect();
        $this->assertSame('확인했어요', $s->fresh()->dispute_reply);

        $old = Settlement::create(['vendor_id' => $this->vendor->id, 'period_start' => now(), 'period_end' => now(), 'order_count' => 0, 'gross_amount' => 0, 'commission_amount' => 0, 'payout_amount' => 0]);
        $old->forceFill(['created_at' => now()->subDays(31)])->save();
        $this->expectException(OrderException::class);
        app(SettlementService::class)->dispute($old->fresh(), '늦은 이의');
    }

    public function test_c2_settings_page_saves_defaults_vendor_and_store_rates(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.save'), [
            'defaults' => ['default_commission_rate' => 7, 'default_settlement_cycle' => 'monthly', 'default_settlement_day' => 10, 'default_payout_delay_days' => 5, 'commission_vat_rate' => 10],
            'vendors' => [$this->vendor->id => ['commission_rate' => 9, 'settlement_cycle' => 'weekly', 'settlement_day' => 5, 'payout_delay_days' => 2]],
            'stores' => [$this->store->id => ['commission_rate' => 6.5]],
        ])->assertRedirect();

        $this->assertSame('7', \App\Models\Setting::get('default_commission_rate'));
        $this->assertSame('weekly', $this->vendor->fresh()->settlement_cycle);
        $this->assertEquals(6.5, (float) $this->store->fresh()->commission_rate);
    }

    // ── D. 구매자 취소 ──

    public function test_d1_buyer_cancels_before_preparing_but_not_after(): void
    {
        $o = app(OrderService::class)->cancelByBuyer($this->order());
        $this->assertSame(OrderStatus::Refunded, $o->status);
        $this->assertSame('구매자 취소', $o->cancel_reason);
        $this->assertSame(10, $this->coffee->fresh()->stock);

        $preparing = app(OrderService::class)->transition($this->order(), OrderStatus::Preparing);
        $this->expectException(OrderException::class);
        app(OrderService::class)->cancelByBuyer($preparing);
    }

    // ── F. 티켓 취소 수수료 ──

    public function test_f3_ticket_cancel_fee_schedule(): void
    {
        $event = Event::create(['slug' => 't', 'name' => 'T', 'artist' => 'A', 'starts_at' => now()->addDays(5)->setTime(18, 0), 'max_per_buyer' => 4]);
        $section = SeatSection::create(['event_id' => $event->id, 'code' => 'A', 'name' => 'A', 'tier' => 'R', 'price' => 150000, 'capacity' => 10, 'ring' => 1, 'start_deg' => 0, 'end_deg' => 30]);
        [$t1, $t2] = app(TicketService::class)->purchase($event, $section, 2, '팬', '01011112222')->all();

        $c = app(TicketService::class)->cancelByBuyer($t1);           // 5일 전 → 20%
        $this->assertSame(30000, $c->cancel_fee);
        $this->assertSame(120000, $c->refund_amount);

        $a = app(TicketService::class)->cancel($t2);                  // 운영사 취소 → 전액
        $this->assertSame(0, $a->cancel_fee);
        $this->assertSame(150000, $a->refund_amount);
        $this->assertSame(0, $section->fresh()->sold);

        $this->travelTo($event->starts_at->copy()->setTime(9, 0));    // 공연 당일
        $t3 = app(TicketService::class)->purchase($event->fresh(), $section->fresh(), 1, '팬2', '01033334444')->first();
        $this->expectException(OrderException::class);
        app(TicketService::class)->cancelByBuyer($t3);
    }

    public function test_e1_purchase_requires_terms_agreement(): void
    {
        $event = Event::create(['slug' => 'e1', 'name' => 'E', 'artist' => 'A', 'starts_at' => now()->addDays(20), 'max_per_buyer' => 4]);
        $section = SeatSection::create(['event_id' => $event->id, 'code' => 'A', 'name' => 'A', 'tier' => 'R', 'price' => 1000, 'capacity' => 10, 'ring' => 1, 'start_deg' => 0, 'end_deg' => 30]);
        $this->post(route('concerts.purchase', $event), ['section_id' => $section->id, 'qty' => 1, 'name' => '팬', 'phone' => '010-1111-2222'])
            ->assertSessionHasErrors('agree_terms');
        $this->assertSame(0, Ticket::count());
    }

    // ── H. 생산·재고 원장 ──

    public function test_h_inventory_ledger_records_every_stock_change(): void
    {
        $inv = app(InventoryService::class);
        $inv->record($this->coffee, null, 'produce', 20, '오전 생산', $this->vendorUser->id);   // 10 → 30
        $inv->record($this->coffee, null, 'discard', 5, '폐기', $this->vendorUser->id);         // 30 → 25
        $o = $this->order([['product_id' => $this->coffee->id, 'qty' => 3]]);                    // 25 → 22 (판매)
        app(OrderService::class)->cancelByBuyer($o);                                             // 22 → 25 (복구)
        $inv->record($this->coffee, null, 'adjust', 12, '당일 리셋', $this->vendorUser->id);     // 25 → 12

        $this->assertSame(12, $this->coffee->fresh()->stock);
        $this->assertSame(['produce', 'discard', 'sale', 'restore', 'adjust'], StockMovement::orderBy('id')->pluck('type')->all());
        $this->assertSame([30, 25, 22, 25, 12], StockMovement::orderBy('id')->pluck('stock_after')->all());
        $this->assertSame(-13, StockMovement::where('type', 'adjust')->value('qty'));

        $this->expectException(OrderException::class);
        $inv->record($this->coffee, null, 'discard', 99, null, null);   // 남은 것보다 많이 폐기 불가
    }

    public function test_h_vendor_inventory_page_and_record(): void
    {
        $this->actingAs($this->vendorUser)->post(route('partner.inventory.record'), ['product_id' => $this->coffee->id, 'type' => 'produce', 'qty' => 5, 'memo' => '추가 생산'])
            ->assertSessionHas('status');
        $this->assertSame(15, $this->coffee->fresh()->stock);
        $this->get(route('partner.inventory'))->assertOk()->assertSee('추가 생산');
    }

    // ── G2. 상세 화면 ──

    public function test_g2_detail_pages_open_from_lists(): void
    {
        $o = $this->pickUp($this->order());
        $event = Event::create(['slug' => 'g', 'name' => 'G', 'artist' => 'A', 'starts_at' => now()->addDays(20), 'max_per_buyer' => 4]);
        app(VenueSetup::class)->createSections($event, ['VIP' => ['price' => 1, 'capacity' => 5], 'R' => ['price' => 1, 'capacity' => 5], 'S' => ['price' => 1, 'capacity' => 5]]);
        $t = app(TicketService::class)->purchase($event, $event->sections()->first(), 1, '팬', '01011112222')->first();

        $this->actingAs($this->admin);
        $this->get(route('admin.orders.index'))->assertOk()->assertSee(route('admin.orders.show', $o));
        $this->get(route('admin.orders.show', $o))->assertOk()->assertSee($o->pickup_code);
        $this->get(route('admin.tickets.show', $t))->assertOk()->assertSee($t->ticket_no);
        $this->get(route('admin.vendors.show', $this->vendor))->assertOk()->assertSee('입점 서류');
        $this->get(route('admin.stores.show', $this->store->id))->assertOk()->assertSee('아메리카노');
        $this->get(route('admin.settings'))->assertOk();

        $this->actingAs($this->vendorUser)->get(route('partner.orders.show', $o))->assertOk()->assertSee($o->pickup_code);
        $other = Store::create(['vendor_id' => Vendor::create(['name' => '남', 'state' => 'approved'])->id, 'code' => 'x', 'name' => 'x']);
        $otherOrder = Order::create(['order_no' => 'x-1', 'pickup_code' => '1111', 'store_id' => $other->id, 'status' => 2, 'pay_method' => 'card', 'total_amount' => 1, 'commission_rate' => 1]);
        $this->get(route('partner.orders.show', $otherOrder))->assertForbidden();
    }
}
