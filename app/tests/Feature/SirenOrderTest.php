<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\CapacitySlot;
use App\Models\Event;
use App\Models\Order;
use App\Models\Product;
use App\Models\SeatSection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderException;
use App\Services\OrderService;
use App\Services\OrderWebhookSender;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SirenOrderTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;
    private Store $store;
    private Product $coffee;
    private CapacitySlot $slot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->event = Event::create([
            'slug' => 'test-show', 'name' => '테스트 공연', 'artist' => '테스트그룹',
            'starts_at' => now()->addDay(), 'sales_open_at' => now()->subHour(), 'max_per_buyer' => 4,
        ]);
        $this->store = Store::create(['code' => 'arena1', 'name' => '아레나 커피']);
        $this->coffee = Product::create(['store_id' => $this->store->id, 'name' => '아메리카노', 'price' => 4500, 'stock' => 5]);
        $this->slot = CapacitySlot::create([
            'event_id' => $this->event->id, 'store_id' => $this->store->id, 'slot' => 'intermission', 'capacity' => 2,
        ]);
    }

    private function section(int $capacity, int $sold = 0): SeatSection
    {
        return SeatSection::create([
            'event_id' => $this->event->id, 'code' => 'A1', 'name' => 'A1구역', 'tier' => 'R', 'price' => 150000,
            'capacity' => $capacity, 'sold' => $sold, 'ring' => 1, 'start_deg' => -30, 'end_deg' => 30,
        ]);
    }

    private function paidOrder(int $qty = 1): Order
    {
        $svc = app(OrderService::class);

        return $svc->confirmPayment($svc->place($this->store, [['product_id' => $this->coffee->id, 'qty' => $qty]], 'card', $this->slot->id));
    }

    // ── 매장 보드 되돌리기 ──

    public function test_store_can_step_back_each_stage_and_undo_pickup_within_window(): void
    {
        Http::fake();
        $svc = app(OrderService::class);
        $order = $this->paidOrder();

        $order = $svc->transition($order, OrderStatus::Preparing);
        $order = $svc->transition($order, OrderStatus::Paid);            // 만드는 중 → 새 주문
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNull($order->preparing_at);

        foreach ([OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::PickedUp] as $s) {
            $order = $svc->transition($order, $s);
        }
        $order = $svc->transition($order, OrderStatus::Ready);           // 건네줬어요 → 픽업 대기
        $this->assertSame(OrderStatus::Ready, $order->status);
        $this->assertNull($order->picked_up_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.reverted']);

        $order = $svc->transition($order, OrderStatus::PickedUp);        // 다시 건넴
        $this->assertSame(OrderStatus::PickedUp, $order->fresh()->status);

        // 10분이 지나면 되돌릴 수 없다
        $order->forceFill(['picked_up_at' => now()->subMinutes(11)])->save();
        $this->assertFalse($order->fresh()->toBoardArray()['can_revert']);
        try {
            $svc->transition($order->fresh(), OrderStatus::Ready);
            $this->fail('되돌려지면 안 됨');
        } catch (OrderException $e) {
            $this->assertSame('revert_expired', $e->reason);
        }

        // 두 칸 건너뛰어 뒤로는 안 된다
        $other = $svc->transition($this->paidOrder(), OrderStatus::Ready);
        $this->expectException(OrderException::class);
        $svc->transition($other, OrderStatus::Paid);
    }

    public function test_board_api_reverts_via_status_patch(): void
    {
        $vendor = \App\Models\Vendor::create(['name' => '업체', 'state' => 'approved']);
        $this->store->update(['vendor_id' => $vendor->id]);
        $user = User::factory()->create(['role' => 'vendor', 'vendor_id' => $vendor->id]);
        $order = app(OrderService::class)->transition($this->paidOrder(), OrderStatus::Preparing);

        $this->actingAs($user, 'web')->patchJson(route('partner.orders.status', $order->order_no), ['status' => OrderStatus::Paid->value])
            ->assertOk()->assertJsonPath('order.status', OrderStatus::Paid->value);
        $this->getJson(route('partner.board.data'))->assertOk()->assertJsonPath('orders.0.can_revert', false);   // 새 주문은 더 뒤로 못 감
    }

    // ── 티켓 ──

    public function test_ticket_purchase_assigns_seats_and_never_oversells(): void
    {
        $section = $this->section(capacity: 3, sold: 1);
        $tickets = app(TicketService::class)->purchase($this->event, $section, 2, '팬', '010-1111-2222');

        $this->assertCount(2, $tickets);
        $this->assertSame([1, 2], $tickets->pluck('seat_no')->all());   // 발급된 티켓 수 기준 번호
        $this->assertSame(3, $section->fresh()->sold);

        $this->expectException(OrderException::class);
        app(TicketService::class)->purchase($this->event, $section, 1, '다른팬', '010-3333-4444');
    }

    public function test_buyer_limit_is_per_phone_across_purchases(): void
    {
        $section = $this->section(capacity: 100);
        app(TicketService::class)->purchase($this->event, $section, 3, '팬', '010-1111-2222');

        try {
            app(TicketService::class)->purchase($this->event, $section, 2, '팬', '01011112222');
            $this->fail('1인 매수 제한을 넘었는데 예매됨');
        } catch (OrderException $e) {
            $this->assertSame('buyer_limit', $e->reason);
        }
        $this->assertSame(3, $section->fresh()->sold);
    }

    public function test_purchase_page_flow_puts_ticket_in_my_tickets(): void
    {
        $section = $this->section(capacity: 10);

        // 비회원은 예매 화면에서 회원 로그인으로 보낸다
        $this->post(route('concerts.purchase', $this->event), ['section_id' => $section->id, 'qty' => 1, 'agree_terms' => '1'])
            ->assertRedirect();
        $this->assertSame(0, \App\Models\Ticket::count());

        $member = $this->actingAsMember();
        $this->post(route('concerts.purchase', $this->event), [
            'section_id' => $section->id, 'qty' => 1, 'agree_terms' => '1',
        ])->assertRedirect(route('tickets'));

        $this->get(route('tickets'))->assertOk()->assertSee('A1');
        $this->assertSame($member->id, \App\Models\Ticket::first()->member_id);
    }

    // ── 매장 주문 ──

    public function test_order_recalculates_price_and_deducts_stock_and_capacity(): void
    {
        $order = $this->paidOrder(qty: 2);

        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame(9000, $order->total_amount);
        $this->assertSame(3, $this->coffee->fresh()->stock);
        $this->assertSame(1, $this->slot->fresh()->used);
        $this->assertSame(1, DB::table('store_notifications')->where('store_id', $this->store->id)->count());
    }

    public function test_capacity_gate_closes_when_full_or_manually_closed(): void
    {
        $this->paidOrder();
        $this->paidOrder();

        try {
            $this->paidOrder();
            $this->fail('캐파를 넘었는데 주문됨');
        } catch (OrderException $e) {
            $this->assertSame('capacity_closed', $e->reason);
        }

        $this->slot->update(['used' => 0, 'is_closed' => true]);
        $this->expectException(OrderException::class);
        $this->paidOrder();
    }

    public function test_out_of_stock_rolls_back_capacity(): void
    {
        try {
            $this->paidOrder(qty: 6);
            $this->fail('재고보다 많이 팔림');
        } catch (OrderException $e) {
            $this->assertSame('out_of_stock', $e->reason);
        }
        $this->assertSame(0, $this->slot->fresh()->used);
        $this->assertSame(0, Order::count());
    }

    public function test_cancel_refunds_and_returns_stock_and_capacity(): void
    {
        $order = app(OrderService::class)->transition($this->paidOrder(qty: 2), OrderStatus::Cancelled);

        $this->assertSame(OrderStatus::Refunded, $order->status);
        $this->assertSame(5, $this->coffee->fresh()->stock);
        $this->assertSame(0, $this->slot->fresh()->used);
    }

    public function test_cannot_cancel_after_preparing_started(): void
    {
        $svc = app(OrderService::class);
        $order = $svc->transition($this->paidOrder(), OrderStatus::Preparing);

        $this->expectException(OrderException::class);
        $svc->transition($order, OrderStatus::Cancelled);
    }

    public function test_pickup_sends_signed_webhook_exactly_once(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $vendor = \App\Models\Vendor::create(['name' => '아레나푸드', 'state' => 'approved']);
        $this->store->update(['vendor_id' => $vendor->id]);
        DB::table('vendor_integrations')->insert([
            'vendor_id' => $vendor->id, 'eis_code' => 'arenafood', 'order_url' => 'https://erp.test/receive', 'secret' => 's3cret',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = app(OrderService::class)->transition($this->paidOrder(), OrderStatus::PickedUp);
        app(OrderWebhookSender::class)->sendOnce($order);   // 버튼 재클릭 흉내 — 다시 보내면 안 됨

        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => $req->header('X-Dadamda-Signature')[0] === 'sha256='.hash_hmac('sha256', $req->body(), 's3cret')
            && $req['orders'][0]['total_amount'] === 4500);
        $this->assertSame('OK (HTTP 200)', DB::table('webhook_deliveries')->value('result'));
    }

    public function test_unpaid_orders_expire_and_release_capacity(): void
    {
        app(OrderService::class)->place($this->store, [['product_id' => $this->coffee->id, 'qty' => 1]], 'card', $this->slot->id);
        $this->travel(config('siren.pending_ttl_minutes') + 1)->minutes();

        $this->assertSame(1, app(OrderService::class)->expirePending());
        $this->assertSame(0, $this->slot->fresh()->used);
        $this->assertSame(5, $this->coffee->fresh()->stock);
    }

    // ── 매장 직원 ──

    public function test_vendor_only_sees_and_changes_own_store_orders(): void
    {
        $order = $this->paidOrder();
        $mineVendor = \App\Models\Vendor::create(['name' => '아레나푸드', 'state' => 'approved']);
        $theirVendor = \App\Models\Vendor::create(['name' => '비어하우스', 'state' => 'approved']);
        $this->store->update(['vendor_id' => $mineVendor->id]);
        Store::create(['vendor_id' => $theirVendor->id, 'code' => 'arena2', 'name' => '비어 스탠드']);
        $mine = User::factory()->create(['role' => 'vendor', 'vendor_id' => $mineVendor->id]);
        $theirs = User::factory()->create(['role' => 'vendor', 'vendor_id' => $theirVendor->id]);

        $this->actingAs($mine)->getJson(route('partner.board.data'))
            ->assertOk()->assertJsonPath('orders.0.pickup_code', $order->pickup_code);

        $this->actingAs($theirs)->getJson(route('partner.board.data'))->assertJsonCount(0, 'orders');
        $this->actingAs($theirs)->patchJson(route('partner.orders.status', $order), ['status' => 3])->assertForbidden();
    }

    public function test_pos_method_is_rejected_for_online_orders(): void
    {
        $this->expectException(OrderException::class);
        app(OrderService::class)->place($this->store, [['product_id' => $this->coffee->id, 'qty' => 1]], 'pos_cash');
    }
}
