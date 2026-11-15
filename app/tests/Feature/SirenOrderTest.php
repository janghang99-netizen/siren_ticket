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

    // ── 티켓 ──

    public function test_ticket_purchase_assigns_seats_and_never_oversells(): void
    {
        $section = $this->section(capacity: 3, sold: 1);
        $tickets = app(TicketService::class)->purchase($this->event, $section, 2, '팬', '010-1111-2222');

        $this->assertCount(2, $tickets);
        $this->assertSame([2, 3], $tickets->pluck('seat_no')->all());
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

        $this->post(route('concerts.purchase', $this->event), [
            'section_id' => $section->id, 'qty' => 1, 'name' => '팬', 'phone' => '010-1234-5678',
        ])->assertRedirect(route('tickets'));

        $this->get(route('tickets'))->assertOk()->assertSee('A1');
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
        DB::table('store_webhooks')->insert([
            'store_id' => $this->store->id, 'url' => 'https://erp.test/receive', 'secret' => 's3cret',
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

    public function test_staff_only_sees_and_changes_own_store_orders(): void
    {
        $order = $this->paidOrder();
        $other = Store::create(['code' => 'arena2', 'name' => '비어 스탠드']);
        $mine = User::factory()->create(['store_id' => $this->store->id]);
        $theirs = User::factory()->create(['store_id' => $other->id]);

        $this->actingAs($mine)->getJson(route('staff.board.data'))
            ->assertOk()->assertJsonPath('orders.0.pickup_code', $order->pickup_code);

        $this->actingAs($theirs)->getJson(route('staff.board.data'))->assertJsonCount(0, 'orders');
        $this->actingAs($theirs)->patchJson(route('staff.orders.status', $order), ['status' => 3])->assertForbidden();
    }

    public function test_pos_method_is_rejected_for_online_orders(): void
    {
        $this->expectException(OrderException::class);
        app(OrderService::class)->place($this->store, [['product_id' => $this->coffee->id, 'qty' => 1]], 'pos_cash');
    }
}
