<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\Product;
use App\Models\SettlementAdjustment;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OrderException;
use App\Services\OrderService;
use App\Services\SettlementService;
use App\Services\TicketService;
use App\Services\VenueSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 정책 2단계: D2 취소 요청 · F4 공연 취소 · C7 공제 등록 · G1 감사 로그 */
class PolicyPhase2Test extends TestCase
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

    private function preparingOrder(?int $slotId = null): Order
    {
        $svc = app(OrderService::class);
        $o = $svc->confirmPayment($svc->place($this->store, [['product_id' => $this->coffee->id, 'qty' => 2]], 'card', $slotId));

        return $svc->transition($o, OrderStatus::Preparing);
    }

    public function test_d2_cancel_request_approved_refunds_and_restores_stock(): void
    {
        $o = app(OrderService::class)->requestCancel($this->preparingOrder(), '공연이 늦어져서요');
        $this->assertTrue($o->hasPendingCancelRequest());
        $this->assertSame(8, $this->coffee->fresh()->stock);

        $this->actingAs($this->vendorUser)->patch(route('partner.orders.cancel-request', $o), ['decision' => 'approve'])->assertRedirect();
        $o->refresh();
        $this->assertSame(OrderStatus::Refunded, $o->status);
        $this->assertSame('approved', $o->cancel_request_result);
        $this->assertSame(10, $this->coffee->fresh()->stock);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'order.cancel_approved')->count());
    }

    public function test_d2_reject_requires_reason_and_request_only_once(): void
    {
        $o = app(OrderService::class)->requestCancel($this->preparingOrder(), '마음이 바뀌었어요');

        try {
            app(OrderService::class)->respondCancel($o, false, '');
            $this->fail('사유 없이 거절됨');
        } catch (OrderException $e) {
            $this->assertSame('reason_required', $e->reason);
        }

        app(OrderService::class)->respondCancel($o, false, '이미 조리가 끝났어요');
        $this->assertSame(OrderStatus::Preparing, $o->fresh()->status);

        $this->expectException(OrderException::class);
        app(OrderService::class)->requestCancel($o->fresh(), '다시 요청');
    }

    public function test_d2_paid_order_should_be_cancelled_directly_not_requested(): void
    {
        $svc = app(OrderService::class);
        $paid = $svc->confirmPayment($svc->place($this->store, [['product_id' => $this->coffee->id, 'qty' => 1]], 'card'));
        $this->expectException(OrderException::class);
        $svc->requestCancel($paid, '이유');
    }

    public function test_f4_cancelling_event_refunds_tickets_and_store_orders(): void
    {
        $event = Event::create(['slug' => 'c', 'name' => '취소될 공연', 'artist' => '테스트그룹', 'starts_at' => now()->addDays(2), 'max_per_buyer' => 4]);
        app(VenueSetup::class)->createSections($event, ['VIP' => ['price' => 100000, 'capacity' => 10], 'R' => ['price' => 1, 'capacity' => 10], 'S' => ['price' => 1, 'capacity' => 10]]);
        app(VenueSetup::class)->ensureSlots($event, $this->store);
        $section = $event->sections()->first();
        app(TicketService::class)->purchase($event, $section, 2, '팬', '01011112222');   // 2일 전이면 원래 수수료 30%
        $slot = $event->capacitySlots()->where('slot', 'intermission')->first();
        $order = $this->preparingOrder($slot->id);

        $this->actingAs($this->admin)->post(route('admin.events.cancel', $event->id), ['reason' => '아티스트 건강 문제', 'confirm_name' => '틀린이름'])
            ->assertSessionHasErrors('confirm_name');

        $this->actingAs($this->admin)->post(route('admin.events.cancel', $event->id), ['reason' => '아티스트 건강 문제', 'confirm_name' => '테스트그룹'])
            ->assertRedirect(route('admin.events.index'));

        $this->assertTrue($event->fresh()->isCancelled());
        $this->assertSame(0, $event->tickets()->where('status', 'paid')->count());
        $this->assertSame(200000, (int) $event->tickets()->sum('refund_amount'));   // 수수료 없이 전액
        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);
        $this->assertSame(10, $this->coffee->fresh()->stock);
        $this->assertTrue($slot->fresh()->is_closed);
        $this->assertFalse($event->fresh()->salesOpen());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'event.cancelled')->count());
    }

    public function test_c7_admin_registers_deduction_applied_to_next_settlement(): void
    {
        $this->actingAs($this->admin)->post(route('admin.vendors.adjustments', $this->vendor), ['amount' => 1000, 'reason' => '판매자 귀책 반품 비용'])->assertRedirect();
        $this->assertSame(1000, SettlementAdjustment::firstOrFail()->amount);

        $svc = app(OrderService::class);
        $o = $this->preparingOrder();
        $svc->transition($svc->transition($o, OrderStatus::Ready), OrderStatus::PickedUp);   // 10000원
        $s = app(SettlementService::class)->generate(now(), now())->first();
        $this->assertSame(1000, $s->deduction_amount);
        $this->assertSame(10000 - 1000 - 100 - 1000, $s->payout_amount);
    }

    public function test_g1_admin_actions_are_audited_with_actor_and_visible(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.products.block', $this->coffee), ['reason' => '허위 표시']);
        $this->actingAs($this->admin)->patch(route('admin.vendors.state', $this->vendor), ['state' => 'suspended', 'reason' => '위반']);

        $logs = DB::table('audit_logs')->orderBy('id')->get();
        $this->assertSame(['product.blocked', 'vendor.suspended'], $logs->pluck('action')->all());
        $this->assertSame($this->admin->id, $logs->first()->user_id);

        $this->get(route('admin.audit'))->assertOk()->assertSee('허위 표시');
        $this->actingAs($this->vendorUser)->get(route('admin.audit'))->assertForbidden();
    }
}
