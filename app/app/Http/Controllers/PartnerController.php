<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\CapacitySlot;
use App\Models\Event;
use App\Models\Order;
use App\Models\Product;
use App\Models\Settlement;
use App\Models\Store;
use App\Services\OrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * 입점업체 콘솔 (원본 seller_order_board.php / my_sell_view.php / my_sell_status.php 대응).
 * 한 업체가 여러 매장을 운영 — 상단에서 매장을 바꿔 가며 주문·상품을 관리하고,
 * 판매·취소·정산 내역은 업체 전체(매장 필터 가능)로 본다.
 */
class PartnerController extends Controller
{
    // ── 매장 ──

    public function switchStore(Request $request, Store $store): RedirectResponse
    {
        abort_unless($store->vendor_id === $request->user()->vendor_id, 403);
        $request->session()->put('partner_store_id', $store->id);

        return back();
    }

    // ── 주문보드 ──

    public function board(Request $request): View
    {
        return view('partner.board', $this->shared($request));
    }

    public function boardData(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);

        // 원본 LIMIT 100 누락 사고 대응: 활성 상태 전부 + 수령완료는 최근 N분만, 최신순.
        $orders = Order::with('items', 'ticket.section')->where('store_id', $store->id)
            ->where(fn ($q) => $q->whereIn('status', array_map(fn ($s) => $s->value, OrderStatus::active()))
                ->orWhere(fn ($q) => $q->where('status', OrderStatus::PickedUp->value)
                    ->where('picked_up_at', '>=', now()->subMinutes(config('siren.board_picked_up_minutes')))))
            ->orderByDesc('id')->get();

        $unread = DB::table('store_notifications')->where('store_id', $store->id)->whereNull('read_at');
        $new = (clone $unread)->count();
        $unread->update(['read_at' => now()]);

        $eventId = Event::where('starts_at', '>', now()->subHours(6))->orderBy('starts_at')
            ->whereHas('capacitySlots', fn ($q) => $q->where('store_id', $store->id))->value('id');
        $slots = CapacitySlot::where('store_id', $store->id)->where('event_id', $eventId)
            ->orderByRaw("CASE slot WHEN 'pre' THEN 0 WHEN 'intermission' THEN 1 ELSE 2 END")->get();

        return response()->json([
            'new_orders' => $new,
            'orders' => $orders->map(fn (Order $o) => $o->toBoardArray() + ['seat' => $o->ticket?->seatLabel()])->values(),
            'slots' => $slots->map(fn (CapacitySlot $s) => [
                'id' => $s->id, 'slot' => $s->slot, 'capacity' => $s->capacity, 'used' => $s->used,
                'is_closed' => $s->is_closed, 'open' => $s->isOpen(), 'closes_at' => $s->closes_at?->format('H:i'),
            ]),
        ]);
    }

    public function updateStatus(Request $request, Order $order, OrderService $orders): JsonResponse
    {
        $this->authorizeStore($request, $order->store_id);
        $to = OrderStatus::from((int) $request->validate(['status' => ['required', 'integer']])['status']);

        return response()->json(['result' => 'ok', 'order' => $orders->transition($order, $to)->toBoardArray()]);
    }

    public function toggleSlot(Request $request, CapacitySlot $slot): JsonResponse
    {
        $this->authorizeStore($request, $slot->store_id);
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
        $order = $orders->placePos($this->currentStore($request), $data['items'], $data['pay_method']);

        return response()->json(['result' => 'ok', 'pickup_code' => $order->pickup_code, 'total' => $order->total_amount]);
    }

    // ── 상품 ──

    public function products(Request $request): View
    {
        $store = $this->currentStore($request);
        $products = Product::with('options')->where('store_id', $store->id)->orderBy('sort')->orderBy('id')->get();

        // 판매 수량 (취소·미결제 제외)
        $sold = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $store->id)
            ->whereIn('orders.status', [2, 3, 4, 5, 6])
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.qty) as qty')
            ->pluck('qty', 'product_id');

        return view('partner.products', $this->shared($request) + ['products' => $products, 'sold' => $sold]);
    }

    public function productCreate(Request $request): View
    {
        return view('partner.product-form', $this->shared($request) + ['product' => null]);
    }

    public function productEdit(Request $request, Product $product): View
    {
        $this->authorizeStore($request, $product->store_id);

        return view('partner.product-form', $this->shared($request) + ['product' => $product->load('options')]);
    }

    public function productStore(Request $request): RedirectResponse
    {
        $product = $this->saveProduct($request, null);

        return redirect()->route('partner.products')->with('status', "‘{$product->name}’을(를) 등록했어요.");
    }

    public function productUpdate(Request $request, Product $product): RedirectResponse
    {
        $this->authorizeStore($request, $product->store_id);
        $this->saveProduct($request, $product);

        return redirect()->route('partner.products')->with('status', "‘{$product->name}’을(를) 저장했어요.");
    }

    public function toggleProduct(Request $request, Product $product): RedirectResponse
    {
        $this->authorizeStore($request, $product->store_id);
        $product->update(['is_visible' => ! $product->is_visible]);

        return back()->with('status', $product->is_visible ? "‘{$product->name}’을(를) 다시 판매해요." : "‘{$product->name}’을(를) 판매 중지했어요.");
    }

    private function saveProduct(Request $request, ?Product $product): Product
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'price' => ['required', 'integer', 'min:0', 'max:10000000'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'stock_deduct_at' => ['required', 'in:order,payment'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'options' => ['array'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.name' => ['required', 'string', 'max:30'],
            'options.*.extra_price' => ['nullable', 'integer', 'min:0'],
            'options.*.stock' => ['nullable', 'integer', 'min:0'],
        ], [
            'name.required' => '상품명을 적어 주세요.',
            'price.required' => '가격을 적어 주세요.',
            'options.*.name.required' => '옵션 이름을 적어 주세요.',
        ]);

        return DB::transaction(function () use ($request, $data, $product) {
            $fields = [
                'name' => $data['name'], 'price' => $data['price'], 'stock' => $data['stock'] ?? null,
                'stock_deduct_at' => $data['stock_deduct_at'], 'is_visible' => $request->boolean('is_visible'),
                'sort' => $data['sort'] ?? 0,
            ];
            if ($product) {
                $product->update($fields);
            } else {
                $product = Product::create($fields + ['store_id' => $this->currentStore($request)->id]);
            }

            $keep = [];
            foreach ($data['options'] ?? [] as $o) {
                $values = ['name' => $o['name'], 'extra_price' => $o['extra_price'] ?? 0, 'stock' => $o['stock'] ?? null];
                $option = ! empty($o['id']) ? $product->options()->find($o['id']) : null;
                if ($option) {
                    $option->update($values);
                } else {
                    $option = $product->options()->create($values);
                }
                $keep[] = $option->id;
            }

            // 지운 옵션: 주문에 쓰인 적 있으면 기록 보존을 위해 품절(재고 0)로만, 아니면 삭제
            foreach ($product->options()->whereNotIn('id', $keep)->get() as $gone) {
                DB::table('order_items')->where('product_option_id', $gone->id)->exists()
                    ? $gone->update(['stock' => 0])
                    : $gone->delete();
            }

            return $product;
        });
    }

    // ── 판매·취소 내역 ──

    public function sales(Request $request): View
    {
        $q = $this->orderQuery($request)->whereIn('status', [2, 3, 4, 5, 6]);

        return view('partner.orders', $this->shared($request) + $this->orderList($request, $q) + [
            'title' => '판매 내역', 'mode' => 'sales',
        ]);
    }

    public function cancels(Request $request): View
    {
        $q = $this->orderQuery($request)->whereIn('status', [11, 14]);

        return view('partner.orders', $this->shared($request) + $this->orderList($request, $q, 'cancelled_at') + [
            'title' => '취소 내역', 'mode' => 'cancels',
        ]);
    }

    // ── 정산 ──

    public function settlements(Request $request): View
    {
        $settlements = Settlement::where('vendor_id', $request->user()->vendor_id)->latest('period_end')->latest('id')->paginate(20);

        // 아직 정산 안 된 수령완료 금액 (다음 정산 예정)
        $pending = Order::whereIn('store_id', $this->vendorStores($request)->pluck('id'))
            ->where('status', OrderStatus::PickedUp->value)->whereNull('settlement_id');

        return view('partner.settlements', $this->shared($request) + [
            'settlements' => $settlements,
            'pendingCount' => (clone $pending)->count(),
            'pendingAmount' => (int) (clone $pending)->sum('total_amount'),
        ]);
    }

    public function settlement(Request $request, Settlement $settlement): View
    {
        abort_unless($settlement->vendor_id === $request->user()->vendor_id, 403);

        return view('partner.settlement', $this->shared($request) + [
            'settlement' => $settlement,
            'orders' => $settlement->orders()->with('store', 'items')->orderBy('picked_up_at')->get(),
        ]);
    }

    // ── 공통 ──

    /** @return Collection<int, Store> */
    private function vendorStores(Request $request): Collection
    {
        // 컨트롤러 인스턴스는 라우트에 캐시되어 요청 간에 재사용될 수 있다 → 캐시는 반드시 요청 객체에 둔다
        if (! $request->attributes->has('vendor_stores')) {
            $request->attributes->set('vendor_stores', Store::where('vendor_id', $request->user()->vendor_id)->orderBy('id')->get());
        }

        return $request->attributes->get('vendor_stores');
    }

    private function currentStore(Request $request): Store
    {
        $stores = $this->vendorStores($request);
        abort_if($stores->isEmpty(), 403, '운영 중인 매장이 없어요. 운영사에 매장 등록을 요청해 주세요.');

        return $stores->firstWhere('id', $request->session()->get('partner_store_id')) ?? $stores->first();
    }

    private function authorizeStore(Request $request, int $storeId): void
    {
        abort_unless($this->vendorStores($request)->contains('id', $storeId), 403);
    }

    private function shared(Request $request): array
    {
        return ['stores' => $this->vendorStores($request), 'store' => $this->currentStore($request), 'vendor' => $request->user()->vendor];
    }

    private function orderQuery(Request $request): Builder
    {
        $storeIds = $this->vendorStores($request)->pluck('id');
        $storeId = (int) $request->query('store');

        return Order::with(['store', 'items', 'ticket.section'])
            ->whereIn('store_id', $storeId && $storeIds->contains($storeId) ? [$storeId] : $storeIds);
    }

    /** 기간 필터 + 합계 + 페이지 */
    private function orderList(Request $request, Builder $q, string $dateColumn = 'created_at'): array
    {
        $from = $request->date('from') ?? now()->subDays(6);
        $to = $request->date('to') ?? now();
        $q->whereBetween($dateColumn, [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total' => (int) (clone $q)->sum('total_amount'),
            'count' => (clone $q)->count(),
            'orders' => $q->orderByDesc($dateColumn)->paginate(30)->withQueryString(),
        ];
    }
}
