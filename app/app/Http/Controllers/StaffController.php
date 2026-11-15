<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\CapacitySlot;
use App\Models\Event;
use App\Models\Order;
use App\Models\Store;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** 입점 매장 직원 화면 (원본 seller_order_board.php / ajax_seller_*). 매장마다 자기 주문만 본다. */
class StaffController extends Controller
{
    public function loginForm(): View
    {
        return view('staff.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $cred = $request->validate(['email' => ['required', 'email'], 'password' => ['required']]);

        if (! Auth::attempt($cred, true) || ! Auth::user()->store_id) {
            Auth::logout();

            return back()->withInput($request->only('email'))->withErrors(['email' => '이메일 또는 비밀번호가 맞지 않아요.']);
        }
        $request->session()->regenerate();

        return redirect()->route('staff.board');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();

        return redirect()->route('staff.login');
    }

    public function board(Request $request): View
    {
        return view('staff.board', ['store' => $this->store($request)]);
    }

    public function boardData(Request $request): JsonResponse
    {
        $store = $this->store($request);

        // 원본 LIMIT 100 누락 사고 대응: 활성 상태 전부 + 수령완료는 최근 N분만, 최신순.
        $orders = Order::with('items', 'ticket.section')->where('store_id', $store->id)
            ->where(fn ($q) => $q->whereIn('status', array_map(fn ($s) => $s->value, OrderStatus::active()))
                ->orWhere(fn ($q) => $q->where('status', OrderStatus::PickedUp->value)
                    ->where('picked_up_at', '>=', now()->subMinutes(config('siren.board_picked_up_minutes')))))
            ->orderByDesc('id')->get();

        // 새 주문 알림 (원본 openmarket_seller_alarm 읽음 처리)
        $unread = DB::table('store_notifications')->where('store_id', $store->id)->whereNull('read_at');
        $new = (clone $unread)->count();
        $unread->update(['read_at' => now()]);

        // 오늘(가장 가까운) 공연의 이 매장 시간대 캐파
        $eventId = Event::where('starts_at', '>', now()->subHours(6))->orderBy('starts_at')
            ->whereHas('capacitySlots', fn ($q) => $q->where('store_id', $store->id))->value('id');
        $slots = CapacitySlot::where('store_id', $store->id)->where('event_id', $eventId)
            ->orderByRaw("CASE slot WHEN 'pre' THEN 0 WHEN 'intermission' THEN 1 ELSE 2 END")->get();

        return response()->json([
            'new_orders' => $new,
            'orders' => $orders->map(fn (Order $o) => $o->toBoardArray() + [
                'seat' => $o->ticket ? $o->ticket->seatLabel() : null,
            ])->values(),
            'slots' => $slots->map(fn (CapacitySlot $s) => [
                'id' => $s->id, 'slot' => $s->slot, 'capacity' => $s->capacity, 'used' => $s->used,
                'is_closed' => $s->is_closed, 'open' => $s->isOpen(),
                'closes_at' => $s->closes_at?->format('H:i'),
            ]),
        ]);
    }

    public function updateStatus(Request $request, Order $order, OrderService $orders): JsonResponse
    {
        abort_unless($order->store_id === $this->store($request)->id, 403);
        $to = OrderStatus::from((int) $request->validate(['status' => ['required', 'integer']])['status']);

        $order = $orders->transition($order, $to);

        return response()->json(['result' => 'ok', 'order' => $order->toBoardArray()]);
    }

    /** 매장 수동 마감/재개 (설계문서 §3: 캐파가 남아도 매장 판단으로 닫을 수 있어야 함) */
    public function toggleSlot(Request $request, CapacitySlot $slot): JsonResponse
    {
        abort_unless($slot->store_id === $this->store($request)->id, 403);
        $slot->update(['is_closed' => ! $slot->is_closed]);

        return response()->json(['result' => 'ok', 'is_closed' => $slot->is_closed]);
    }

    public function posOrder(Request $request, OrderService $orders): JsonResponse
    {
        $data = $request->validate([
            'pay_method' => ['required', 'in:'.implode(',', config('siren.pos_methods'))],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.option_id' => ['nullable', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.dine_option' => ['nullable', 'in:매장,포장'],
        ]);

        $order = $orders->placePos($this->store($request), $data['items'], $data['pay_method']);

        return response()->json(['result' => 'ok', 'pickup_code' => $order->pickup_code, 'total' => $order->total_amount]);
    }

    private function store(Request $request): Store
    {
        return Store::findOrFail($request->user()->store_id);
    }
}
