<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\CapacitySlot;
use App\Models\Event;
use App\Models\Order;
use App\Models\SeatSection;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\OrderException;
use App\Services\OrderService;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** 팬(관객) 화면 — 로그인 없이 휴대폰 번호 + 세션으로 내 티켓/주문을 찾는다. */
class FanController extends Controller
{
    public function home(): View
    {
        $events = Event::with('sections')->where('starts_at', '>', now()->subHours(6))->orderBy('starts_at')->get();

        return view('fan.home', ['events' => $events, 'headliner' => $events->first()]);
    }

    public function concert(Event $event): View
    {
        $event->load('sections');

        return view('fan.concert', ['event' => $event]);
    }

    public function purchase(Request $request, Event $event, TicketService $tickets): RedirectResponse
    {
        $data = $request->validate([
            'section_id' => ['required', 'integer'],
            'qty' => ['required', 'integer', 'min:1', 'max:'.$event->max_per_buyer],
            'name' => ['required', 'string', 'max:30'],
            'phone' => ['required', 'regex:/^01[0-9][- ]?\d{3,4}[- ]?\d{4}$/'],
            'agree_terms' => ['accepted'],
        ], [
            'agree_terms.accepted' => '이용약관·개인정보 수집·취소 규정에 동의해야 예매할 수 있어요.',
            'section_id.required' => '구역을 먼저 골라 주세요.',
            'name.required' => '예매자 이름을 적어 주세요.',
            'phone.required' => '휴대폰 번호를 적어 주세요.',
            'phone.regex' => '휴대폰 번호를 010-1234-5678 형식으로 적어 주세요.',
        ]);

        $section = SeatSection::findOrFail($data['section_id']);

        try {
            $bought = $tickets->purchase($event, $section, (int) $data['qty'], $data['name'], $data['phone'], now());
        } catch (OrderException $e) {
            return back()->withInput()->withErrors(['section_id' => $e->getMessage()]);
        }

        $request->session()->put('ticket_nos', array_values(array_unique(array_merge(
            $request->session()->get('ticket_nos', []), $bought->pluck('ticket_no')->all()
        ))));

        return redirect()->route('tickets')->with('just_bought', $bought->count());
    }

    public function tickets(Request $request): View
    {
        $phone = preg_replace('/\D/', '', (string) $request->query('phone'));
        if ($phone !== '') {
            $found = Ticket::where('holder_phone', $phone)->pluck('ticket_no')->all();
            $request->session()->put('ticket_nos', array_values(array_unique(array_merge($request->session()->get('ticket_nos', []), $found))));
        }

        // 취소한 티켓도 내역으로 보여 준다 (취소 수수료·환불액 확인)
        $tickets = Ticket::with(['event', 'section'])->whereIn('ticket_no', $request->session()->get('ticket_nos', []))
            ->get()->sortBy(fn ($t) => [$t->status === 'cancelled' ? 1 : 0, $t->event->starts_at])->values();

        return view('fan.tickets', ['tickets' => $tickets, 'searched' => $phone !== '']);
    }

    public function stores(Request $request, Event $event): View
    {
        $stores = Store::whereHas('capacitySlots', fn ($q) => $q->where('event_id', $event->id))
            ->whereHas('vendor', fn ($q) => $q->where('state', 'approved'))
            ->with(['capacitySlots' => fn ($q) => $q->where('event_id', $event->id), 'products' => fn ($q) => $q->sellable()->orderBy('sort')])
            ->orderBy('id')->get();

        return view('fan.stores', ['event' => $event, 'stores' => $stores, 'ticket' => $this->ticketFor($request, $event)]);
    }

    public function menu(Request $request, Event $event, Store $store): View
    {
        $store->load(['vendor', 'products' => fn ($q) => $q->sellable()->orderBy('sort')->with('options')]);
        $slots = CapacitySlot::where('event_id', $event->id)->where('store_id', $store->id)
            ->orderByRaw("CASE slot WHEN 'pre' THEN 0 WHEN 'intermission' THEN 1 ELSE 2 END")->get();

        return view('fan.menu', [
            'event' => $event, 'store' => $store, 'slots' => $slots,
            'ticket' => $this->ticketFor($request, $event),
        ]);
    }

    public function placeOrder(Request $request, Event $event, Store $store, OrderService $orders): JsonResponse
    {
        $data = $request->validate([
            'slot_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.option_id' => ['nullable', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.dine_option' => ['nullable', 'in:매장,포장'],
            'agree_terms' => ['accepted'],
            'adult_confirmed' => ['boolean'],
        ], ['agree_terms.accepted' => '주문 전에 이용약관과 판매자 정보 제공에 동의해 주세요.']);

        $order = $orders->place($store, $data['items'], 'card', (int) $data['slot_id'],
            ticket: $this->ticketFor($request, $event),
            adultConfirmed: (bool) ($data['adult_confirmed'] ?? false),
            termsAgreedAt: now());

        $request->session()->push('order_nos', $order->order_no);

        return response()->json(['result' => 'ok', 'pay_url' => route('orders.pay', $order)]);
    }

    public function pay(Request $request, Order $order): View
    {
        $this->authorizeOrder($request, $order);

        return view('fan.pay', ['order' => $order->load(['items', 'store'])]);
    }

    /** 개발용 결제 승인. 실서비스에서는 PG 콜백(서명 검증)이 이 자리를 대신한다. */
    public function confirm(Request $request, Order $order, OrderService $orders): RedirectResponse
    {
        $this->authorizeOrder($request, $order);
        $order = $orders->confirmPayment($order, 'MOCK-'.now()->format('His'));

        if ($order->status !== OrderStatus::Paid) {
            return redirect()->route('orders.show', $order)->withErrors(['order' => '결제 시간이 지나 주문이 취소됐어요. 다시 주문해 주세요.']);
        }

        return redirect()->route('orders.show', $order);
    }

    public function order(Request $request, Order $order): View
    {
        $this->authorizeOrder($request, $order);

        return view('fan.order', ['order' => $order->load(['items', 'store', 'ticket.section', 'capacitySlot'])]);
    }

    public function orderStatus(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        return response()->json(['status' => $order->status->value, 'label' => $order->status->label()]);
    }

    public function myOrders(Request $request): View
    {
        $tabs = ['active' => [2, 3, 4], 'done' => [5, 6], 'cancelled' => [11, 14]];
        $all = Order::with(['store', 'items'])->whereIn('order_no', $request->session()->get('order_nos', []))
            ->where('status', '!=', OrderStatus::Pending->value)->latest()->get();
        $tab = array_key_exists($request->query('tab'), $tabs) ? $request->query('tab') : 'active';

        return view('fan.orders', [
            'tab' => $tab,
            'counts' => collect($tabs)->map(fn ($s) => $all->filter(fn ($o) => in_array($o->status->value, $s, true))->count()),
            'orders' => $all->filter(fn ($o) => in_array($o->status->value, $tabs[$tab], true))->values(),
        ]);
    }

    /** D1 구매자 취소: 매장이 준비를 시작하기 전까지 */
    public function cancelOrder(Request $request, Order $order, OrderService $orders): RedirectResponse
    {
        $this->authorizeOrder($request, $order);
        $orders->cancelByBuyer($order);

        return redirect()->route('orders.show', $order)->with('status', '주문을 취소했어요. 결제한 금액은 결제 수단으로 환불돼요.');
    }

    /** F3 티켓 취소: 취소 수수료 규정 적용 */
    public function cancelTicket(Request $request, Ticket $ticket, TicketService $tickets): RedirectResponse
    {
        abort_unless(in_array($ticket->ticket_no, $request->session()->get('ticket_nos', []), true), 404);
        $t = $tickets->cancelByBuyer($ticket);

        return redirect()->route('tickets')->with('status', $t->cancel_fee
            ? '예매를 취소했어요. 취소 수수료 '.number_format($t->cancel_fee).'원을 빼고 '.number_format($t->refund_amount).'원이 환불돼요.'
            : '예매를 취소했어요. '.number_format($t->refund_amount).'원 전액 환불돼요.');
    }

    public function policy(string $doc): View
    {
        return view('fan.policy', ['doc' => $doc]);
    }

    /** 픽업 전광판 (원본 order_board_view.php). 매장 앞 모니터에 띄운다. */
    public function display(Store $store): View
    {
        return view('display.pickup', ['store' => $store]);
    }

    public function displayData(Store $store): JsonResponse
    {
        $rows = Order::where('store_id', $store->id)
            ->whereIn('status', [OrderStatus::Preparing->value, OrderStatus::Ready->value])
            ->orderByDesc('ready_at')->orderByDesc('preparing_at')->limit(60)
            ->get(['pickup_code', 'status']);

        return response()->json([
            'ready' => $rows->where('status', OrderStatus::Ready)->pluck('pickup_code')->values(),
            'preparing' => $rows->where('status', OrderStatus::Preparing)->pluck('pickup_code')->values(),
        ]);
    }

    private function ticketFor(Request $request, Event $event): ?Ticket
    {
        $nos = $request->session()->get('ticket_nos', []);
        if ($nos === []) {
            return null;
        }
        $wanted = $request->query('ticket', $request->session()->get("active_ticket.{$event->id}"));
        $ticket = Ticket::with('section')->where('event_id', $event->id)->whereIn('ticket_no', $nos)
            ->when($wanted, fn ($q) => $q->orderByRaw('ticket_no = ? desc', [$wanted]))->first();
        if ($ticket) {
            $request->session()->put("active_ticket.{$event->id}", $ticket->ticket_no);
        }

        return $ticket;
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        abort_unless(in_array($order->order_no, $request->session()->get('order_nos', []), true), 404);
    }
}
