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
            ->orderBy('id')->get();   // 먼저 들어온 주문부터 (주방 순서)

        $unread = DB::table('store_notifications')->where('store_id', $store->id)->whereNull('read_at');
        $new = (clone $unread)->count();
        $unread->update(['read_at' => now()]);

        $eventId = Event::where('starts_at', '>', now()->subHours(6))->orderBy('starts_at')
            ->whereHas('capacitySlots', fn ($q) => $q->where('store_id', $store->id))->value('id');
        $slots = CapacitySlot::where('store_id', $store->id)->where('event_id', $eventId)
            ->orderByRaw("CASE slot WHEN 'pre' THEN 0 WHEN 'intermission' THEN 1 ELSE 2 END")->get();

        // 보드의 품절 관리 패널용 (판매 중인 상품만)
        $products = Product::menuOrder(Product::with(['options', 'category'])->where('store_id', $store->id)->sellable()->get())
            ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'category' => $p->category?->name,
                'sold_out' => (bool) $p->sold_out_at, 'stock_out' => ! $p->sold_out_at && $p->isSoldOut()]);

        return response()->json([
            'products' => $products,
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

    /** D2 구매자 취소 요청 승인·거절 (거절은 사유 필수) */
    public function respondCancel(Request $request, Order $order, OrderService $orders): RedirectResponse
    {
        $this->authorizeStore($request, $order->store_id);
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'response' => ['nullable', 'string', 'max:200']]);
        $approve = $data['decision'] === 'approve';
        $orders->respondCancel($order, $approve, $data['response'] ?? null);
        \App\Services\Audit::log($approve ? 'order.cancel_approved' : 'order.cancel_rejected', $order,
            "{$order->store->name} 주문 {$order->pickup_code} 취소 요청 ".($approve ? '승인 · 환불' : '거절: '.$data['response']));

        return back()->with('status', $approve ? '취소를 승인하고 환불했어요.' : '취소 요청을 거절했어요. 사유가 구매자에게 보여요.');
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
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.dine_option' => ['nullable', 'in:매장,포장'],
            // 현장 카드 단말기 (준비 단계): 카드결제면 단말기·승인번호를 남겨 단말기 매출과 대사
            'terminal_id' => ['nullable', 'integer', 'required_if:pay_method,pos_card'],
            'card_approval_no' => ['nullable', 'string', 'max:30', 'required_if:pay_method,pos_card'],
            // 원본 stamp_phone: 손님 휴대폰 번호를 받으면 수령 시 스탬프·적립
            'stamp_phone' => ['nullable', 'regex:/^01[0-9][- ]?\d{3,4}[- ]?\d{4}$/'],
        ], ['stamp_phone.regex' => '휴대폰 번호를 010-1234-5678 형식으로 적어 주세요.']);
        $store = $this->currentStore($request);
        if (! empty($data['terminal_id'])) {
            abort_unless(DB::table('store_terminals')->where('id', $data['terminal_id'])->where('store_id', $store->id)->where('is_active', true)->exists(), 422, '이 매장 단말기가 아니에요.');
        }
        $customer = ! empty($data['stamp_phone']) ? \App\Models\Customer::forPhone($data['stamp_phone']) : null;
        $order = $orders->placePos($store, $data['items'], $data['pay_method'], $customer);
        if (! empty($data['terminal_id'])) {
            $order->update(['terminal_id' => $data['terminal_id'], 'card_approval_no' => $data['card_approval_no'], 'card_approved_at' => now()]);
        }

        return response()->json(['result' => 'ok', 'order_no' => $order->order_no, 'pickup_code' => $order->pickup_code, 'total' => $order->total_amount,
            'ticket_url' => route('partner.orders.ticket', $order)]);
    }

    /** 현장판매 화면 — 매장 PC(카드 단말기 연결)에서 주문을 받고 번호표를 인쇄한다 */
    public function pos(Request $request): View
    {
        $store = $this->currentStore($request);
        $products = Product::menuOrder(Product::with(['options', 'category'])->where('store_id', $store->id)->sellable()->get());
        $recent = Order::with('items')->where('store_id', $store->id)->where('channel', 'pos')
            ->where('created_at', '>=', now()->startOfDay())->latest('id')->limit(20)->get();

        return view('partner.pos', $this->shared($request) + [
            'menu' => $products->map(fn (Product $p) => [
                'id' => $p->id, 'name' => $p->name, 'cat' => $p->categoryName(), 'price' => $p->price, 'img' => $p->imageUrl(),
                'alcohol' => $p->is_alcohol, 'soldOut' => $p->isSoldOut(),
                'options' => $p->options->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'extra' => $o->extra_price, 'soldOut' => $o->stock !== null && $o->stock <= 0])->values(),
            ])->values(),
            'terminals' => DB::table('store_terminals')->where('store_id', $store->id)->where('is_active', true)->orderBy('id')->get(['id', 'label', 'tid', 'provider']),
            'recent' => $recent->map(fn (Order $o) => ['order_no' => $o->order_no, 'pickup_code' => $o->pickup_code, 'total' => $o->total_amount,
                'at' => $o->created_at->format('H:i'), 'items' => $o->items->sum('qty'), 'ticket_url' => route('partner.orders.ticket', $o)])->values(),
        ]);
    }

    /**
     * 번호표 · 주방 주문서 인쇄 화면 (80mm / 58mm 영수증 프린터).
     * ?kind=customer(손님 번호표) | kitchen(주방 주문서), ?width=80|58, ?auto=1 이면 열리자마자 인쇄.
     */
    public function ticket(Request $request, Order $order): View
    {
        $this->authorizeStore($request, $order->store_id);

        return view('partner.ticket', [
            'order' => $order->load(['items', 'store', 'capacitySlot']),
            'kind' => $request->query('kind') === 'kitchen' ? 'kitchen' : 'customer',
            'width' => (int) $request->query('width') === 58 ? 58 : 80,
            'auto' => $request->boolean('auto'),
            'reprint' => $request->boolean('reprint'),
        ]);
    }

    // ── 상품 ──

    public function products(Request $request): View
    {
        $store = $this->currentStore($request);
        $products = Product::menuOrder(Product::with(['options', 'category'])->where('store_id', $store->id)->get());

        // 판매 수량 (취소·미결제 제외)
        $sold = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $store->id)
            ->whereIn('orders.status', [2, 3, 4, 5, 6])
            ->groupBy('order_items.product_id')
            ->select('order_items.product_id')->selectRaw('SUM(qty) as qty')   // 표 이름을 raw 에 쓰지 않는다 (DB 접두어 대응)
            ->pluck('qty', 'product_id');

        return view('partner.products', $this->shared($request) + ['products' => $products, 'sold' => $sold, 'categories' => $this->categories($request)]);
    }

    public function productCreate(Request $request): View
    {
        return view('partner.product-form', $this->shared($request) + ['product' => null, 'categories' => $this->categories($request)]);
    }

    public function productEdit(Request $request, Product $product): View
    {
        $this->authorizeStore($request, $product->store_id);

        return view('partner.product-form', $this->shared($request) + ['product' => $product->load('options'), 'categories' => $this->categories($request)]);
    }

    /** 이 매장의 카테고리 (순서대로, 상품 수 포함) */
    private function categories(Request $request): \Illuminate\Support\Collection
    {
        return \App\Models\ProductCategory::where('store_id', $this->currentStore($request)->id)
            ->withCount('products')->orderBy('sort')->orderBy('id')->get();
    }

    // ── 카테고리 관리 (매장별: 추가 · 이름 · 순서 · 숨기기 · 삭제) ──

    public function categoryStore(Request $request): RedirectResponse
    {
        $store = $this->currentStore($request);
        $name = trim((string) $request->validate(['name' => ['required', 'string', 'max:30']], ['name.required' => '카테고리 이름을 적어 주세요.'])['name']);
        $this->createCategory($store->id, $name);

        return back()->with('status', "카테고리 ‘{$name}’을(를) 만들었어요.");
    }

    public function categoryUpdate(Request $request, \App\Models\ProductCategory $category): RedirectResponse
    {
        $this->authorizeStore($request, $category->store_id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:30', \Illuminate\Validation\Rule::unique('product_categories', 'name')->where('store_id', $category->store_id)->ignore($category->id)],
            'is_visible' => ['nullable', 'boolean'],
        ], ['name.unique' => '같은 이름의 카테고리가 이미 있어요.', 'name.required' => '카테고리 이름을 적어 주세요.']);
        $category->update(['name' => trim($data['name']), 'is_visible' => $request->boolean('is_visible')]);

        return back()->with('status', "카테고리 ‘{$category->name}’을(를) 저장했어요.".($category->is_visible ? '' : ' 숨겨서 손님 메뉴·현장판매에서 빠졌어요.'));
    }

    /** 위·아래로 한 칸 — 순서를 1,2,3… 으로 다시 매긴다 */
    public function categoryMove(Request $request, \App\Models\ProductCategory $category): RedirectResponse
    {
        $this->authorizeStore($request, $category->store_id);
        $dir = $request->validate(['dir' => ['required', 'in:up,down']])['dir'];
        $items = \App\Models\ProductCategory::where('store_id', $category->store_id)->orderBy('sort')->orderBy('id')->get()->values()->all();
        $i = collect($items)->search(fn ($c) => $c->id === $category->id);
        $j = $dir === 'up' ? $i - 1 : $i + 1;
        if ($j >= 0 && $j < count($items)) {
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
            DB::transaction(function () use ($items) {
                foreach ($items as $n => $c) {
                    $c->update(['sort' => $n + 1]);
                }
            });
        }

        return back();
    }

    /** 삭제 — 그 카테고리 상품은 지우지 않고 '기타' 로 옮긴다 */
    public function categoryDestroy(Request $request, \App\Models\ProductCategory $category): RedirectResponse
    {
        $this->authorizeStore($request, $category->store_id);
        $moved = $category->products()->count();
        Product::where('category_id', $category->id)->update(['category_id' => null]);
        $category->delete();

        return back()->with('status', "카테고리 ‘{$category->name}’을(를) 지웠어요.".($moved ? " 상품 {$moved}개는 ‘기타’로 옮겼어요." : ''));
    }

    private function createCategory(int $storeId, string $name): \App\Models\ProductCategory
    {
        return \App\Models\ProductCategory::firstOrCreate(
            ['store_id' => $storeId, 'name' => $name],
            ['sort' => (int) \App\Models\ProductCategory::where('store_id', $storeId)->max('sort') + 1, 'is_visible' => true],
        );
    }

    /** 상품 폼의 카테고리: 고른 것(이 매장 것만) 또는 새로 적은 이름(없으면 만든다) */
    private function resolveCategory(Request $request, array $data): ?int
    {
        $storeId = $this->currentStore($request)->id;
        if (filled($data['new_category'] ?? null)) {
            return $this->createCategory($storeId, trim($data['new_category']))->id;
        }
        if (! empty($data['category_id'])) {
            $id = \App\Models\ProductCategory::where('store_id', $storeId)->whereKey($data['category_id'])->value('id');
            if (! $id) {
                throw \Illuminate\Validation\ValidationException::withMessages(['category_id' => '이 매장의 카테고리가 아니에요.']);
            }

            return $id;
        }

        return null;
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

    /** 주문보드에서 바로 품절 켜기·끄기 (재고 숫자는 그대로 두고 판매만 막는다) */
    public function toggleSoldOut(Request $request, Product $product): JsonResponse
    {
        $this->authorizeStore($request, $product->store_id);
        $product->update(['sold_out_at' => $product->sold_out_at ? null : now()]);

        return response()->json(['result' => 'ok', 'sold_out' => (bool) $product->sold_out_at]);
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
            'category_id' => ['nullable', 'integer'],
            'new_category' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:500'],
            'origin' => ['nullable', 'string', 'max:200'],
            'allergens' => ['nullable', 'string', 'max:200'],
            'price' => ['required', 'integer', 'min:0', 'max:10000000'],
            // 1개당 적립 마일리지 (업체 부담, 판매가의 상한 % 이내 — 원본 auction_product.point)
            'mileage_points' => ['nullable', 'integer', 'min:0', 'max:'.(int) floor((int) $request->input('price') * (float) \App\Models\Setting::get('mileage_product_max_rate') / 100)],
            'stock' => ['nullable', 'integer', 'min:0'],
            'stock_deduct_at' => ['required', 'in:order,payment'],
            'sort' => ['nullable', 'integer', 'min:0'],
            // 상품 이미지: jpg·png·webp, 5MB 까지, 너무 작은 그림은 받지 않는다
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=200,min_height=200,max_width=6000,max_height=6000'],
            'remove_image' => ['nullable', 'boolean'],
            // B1 판매 금지 품목이 아님을 확인 (입점계약 제6조)
            'agree_prohibited' => [$product ? 'nullable' : 'accepted'],
            'options' => ['array'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.name' => ['required', 'string', 'max:30'],
            'options.*.extra_price' => ['nullable', 'integer', 'min:0'],
            'options.*.stock' => ['nullable', 'integer', 'min:0'],
        ], [
            'name.required' => '상품명을 적어 주세요.',
            'price.required' => '가격을 적어 주세요.',
            'options.*.name.required' => '옵션 이름을 적어 주세요.',
            'agree_prohibited.accepted' => '판매 금지 품목이 아닌지 확인해 주세요.',
            'image.image' => '이미지 파일만 올릴 수 있어요.',
            'image.mimes' => 'JPG·PNG·WEBP 이미지만 올릴 수 있어요.',
            'image.max' => '이미지는 5MB 까지 올릴 수 있어요.',
            'image.dimensions' => '이미지는 가로·세로 200~6000픽셀이어야 해요.',
        ]);

        // B2 주류는 주류판매 신고번호가 있는 업체만
        if ($request->boolean('is_alcohol') && ! $request->user()->vendor->liquor_license_no) {
            throw \Illuminate\Validation\ValidationException::withMessages(['is_alcohol' => '주류판매 신고번호가 등록된 업체만 주류를 팔 수 있어요. 운영사에 신고번호 등록을 요청해 주세요.']);
        }

        return DB::transaction(function () use ($request, $data, $product) {
            $fields = [
                'name' => $data['name'], 'category_id' => $this->resolveCategory($request, $data), 'price' => $data['price'], 'mileage_points' => (int) ($data['mileage_points'] ?? 0),
                'description' => $data['description'] ?? null, 'origin' => $data['origin'] ?? null, 'allergens' => $data['allergens'] ?? null,
                'is_alcohol' => $request->boolean('is_alcohol'),
                'stock_deduct_at' => $data['stock_deduct_at'], 'is_visible' => $request->boolean('is_visible'),
                'sort' => $data['sort'] ?? 0,
            ];
            // 재고 수량은 새 상품일 때만 폼에서 받는다. 이후 변경은 생산·재고 화면(원장 기록)에서.
            if (! $product) {
                $fields['stock'] = $data['stock'] ?? null;
            }
            // 이미지: 새로 올리면 바꾸고, 지우기를 고르면 뺀다. 옛 파일은 저장이 끝난 뒤 지운다.
            $oldImage = $product?->image_path;
            if ($request->hasFile('image')) {
                // 긴 변 1000px WEBP 로 줄여 저장 (팬 화면 데이터 절약)
                $fields['image_path'] = app(\App\Services\ProductImageStore::class)->store($request->file('image'));
            } elseif ($request->boolean('remove_image')) {
                $fields['image_path'] = null;
            }
            if ($oldImage && array_key_exists('image_path', $fields) && $fields['image_path'] !== $oldImage) {
                DB::afterCommit(fn () => \Illuminate\Support\Facades\Storage::disk('local')->delete($oldImage));
            }
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
                    unset($values['stock']);   // 기존 옵션 재고는 생산·재고 화면에서만 바꾼다(원장 기록)
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

    // ── 생산·재고 (H) ──

    public function inventory(Request $request): View
    {
        $store = $this->currentStore($request);
        $products = Product::with('options')->where('store_id', $store->id)->orderBy('sort')->orderBy('id')->get();

        $from = $request->date('from') ?? now()->subDays(6);
        $to = $request->date('to') ?? now();
        $type = array_key_exists((string) $request->query('type'), \App\Models\StockMovement::TYPES) ? $request->query('type') : null;

        $q = \App\Models\StockMovement::with(['product', 'option', 'order', 'user'])->where('store_id', $store->id)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($type, fn ($q, $t) => $q->where('type', $t));

        // 기간 합계 (생산·폐기·판매)
        $totals = (clone $q)->reorder()->groupBy('type')->selectRaw('type, SUM(qty) as qty')->pluck('qty', 'type');

        return view('partner.inventory', $this->shared($request) + [
            'products' => $products,
            'movements' => $q->latest('id')->paginate(40)->withQueryString(),
            'totals' => $totals,
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'type' => $type,
        ]);
    }

    public function inventoryRecord(Request $request, \App\Services\InventoryService $inventory): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'option_id' => ['nullable', 'integer'],
            'type' => ['required', 'in:produce,discard,adjust'],
            'qty' => ['required', 'integer', 'min:0', 'max:100000'],
            'memo' => ['nullable', 'string', 'max:100'],
        ], ['qty.required' => '수량을 적어 주세요.']);

        $product = Product::findOrFail($data['product_id']);
        $this->authorizeStore($request, $product->store_id);
        $option = ! empty($data['option_id']) ? $product->options()->findOrFail($data['option_id']) : null;

        $m = $inventory->record($product, $option, $data['type'], (int) $data['qty'], $data['memo'] ?? null, $request->user()->id);
        $label = $product->name.($option ? " ({$option->name})" : '');

        return back()->with('status', "{$label} {$m->typeLabel()} 기록했어요. 지금 재고 {$m->stock_after}개.");
    }

    // ── 생산관리 (원본 production.php) ──

    public function production(Request $request): View
    {
        $store = $this->currentStore($request);
        $date = $request->date('date') ?? now();

        return view('partner.production', $this->shared($request) + [
            // 생산량 입력은 판매 중인 상품만 (판매중지 상품의 지난 생산 기록은 아래 기록 목록에 그대로 보인다)
            'products' => Product::with('options')->where('store_id', $store->id)->where('is_visible', true)->orderBy('sort')->orderBy('id')->get(),
            'date' => $date->toDateString(),
            'records' => DB::table('product_productions as pp')->join('products as p', 'p.id', '=', 'pp.product_id')
                ->leftJoin('product_options as o', 'o.id', '=', 'pp.product_option_id')
                ->where('pp.store_id', $store->id)->where('pp.production_date', $date->toDateString())
                ->select('pp.*', 'p.name as product_name', 'o.name as option_name')->orderByDesc('pp.id')->get(),
        ]);
    }

    /** 일괄 저장: qty[상품id:옵션id] = 오늘 생산량 (빈칸은 건너뜀, 0은 재고 0) */
    public function productionSave(Request $request, \App\Services\EisService $eis): RedirectResponse
    {
        $data = $request->validate(['qty' => ['array'], 'qty.*' => ['nullable', 'integer', 'min:0', 'max:100000'], 'memo' => ['array'], 'memo.*' => ['nullable', 'string', 'max:100']]);
        $saved = 0;
        foreach ($data['qty'] ?? [] as $key => $qty) {
            if ($qty === null || $qty === '') {
                continue;
            }
            [$pid, $oid] = array_pad(explode(':', (string) $key), 2, '');
            $product = Product::findOrFail((int) $pid);
            $this->authorizeStore($request, $product->store_id);
            $option = $oid !== '' ? $product->options()->findOrFail((int) $oid) : null;
            $eis->recordProduction($product, $option, (int) $qty, $data['memo'][$key] ?? null, $request->user()->id);
            $saved++;
        }

        return back()->with('status', $saved ? "생산 {$saved}건을 저장했어요. 재고가 오늘 생산량으로 바뀌었어요." : '입력한 수량이 없어요.');
    }

    public function productionUpdate(Request $request, int $id, \App\Services\EisService $eis): RedirectResponse
    {
        $row = DB::table('product_productions')->find($id) ?? abort(404);
        $this->authorizeStore($request, $row->store_id);
        $data = $request->validate(['qty' => ['required', 'integer', 'min:0'], 'memo' => ['nullable', 'string', 'max:100']]);
        $eis->updateProduction($id, (int) $data['qty'], $data['memo'] ?? null, $request->user()->id);

        return back()->with('status', '생산 기록을 고쳤어요.');
    }

    public function productionDelete(Request $request, int $id, \App\Services\EisService $eis): RedirectResponse
    {
        $row = DB::table('product_productions')->find($id) ?? abort(404);
        $this->authorizeStore($request, $row->store_id);
        $eis->deleteProduction($id);

        return back()->with('status', '생산 기록을 지웠어요. 재고는 그대로예요.');
    }

    // ── 주문 상세 ──

    public function order(Request $request, Order $order): View
    {
        $this->authorizeStore($request, $order->store_id);

        return view('partner.order', $this->shared($request) + [
            'order' => $order->load(['store', 'items', 'ticket.section', 'ticket.event', 'capacitySlot', 'settlement']),
            'movements' => \App\Models\StockMovement::with(['product', 'option'])->where('order_id', $order->id)->orderBy('id')->get(),
        ]);
    }

    public function dispute(Request $request, Settlement $settlement, \App\Services\SettlementService $service): RedirectResponse
    {
        abort_unless($settlement->vendor_id === $request->user()->vendor_id, 403);
        $message = $request->validate(['message' => ['required', 'string', 'max:2000']], ['message.required' => '이의 내용을 적어 주세요.'])['message'];
        $service->dispute($settlement, $message);

        return back()->with('status', '이의제기를 보냈어요. 운영사가 확인하고 이 화면에 답변해요.');
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
    protected function vendorStores(Request $request): Collection
    {
        // 컨트롤러 인스턴스는 라우트에 캐시되어 요청 간에 재사용될 수 있다 → 캐시는 반드시 요청 객체에 둔다
        if (! $request->attributes->has('vendor_stores')) {
            $request->attributes->set('vendor_stores', Store::where('vendor_id', $request->user()->vendor_id)->orderBy('id')->get());
        }

        return $request->attributes->get('vendor_stores');
    }

    protected function currentStore(Request $request): Store
    {
        $stores = $this->vendorStores($request);
        abort_if($stores->isEmpty(), 403, '운영 중인 매장이 없어요. 운영사에 매장 등록을 요청해 주세요.');

        return $stores->firstWhere('id', $request->session()->get('partner_store_id')) ?? $stores->first();
    }

    protected function authorizeStore(Request $request, int $storeId): void
    {
        abort_unless($this->vendorStores($request)->contains('id', $storeId), 403);
    }

    protected function shared(Request $request): array
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
