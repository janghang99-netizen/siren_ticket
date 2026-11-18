<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\CapacitySlot;
use App\Models\Coupon;
use App\Models\CouponIssue;
use App\Models\Customer;
use App\Models\Event;
use App\Models\StampCard;
use App\Models\StampProgram;
use App\Services\Benefits\BenefitService;
use App\Services\Benefits\PhoneVerificationService;
use App\Models\Order;
use App\Models\Production;
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
        // 공연 기능을 켜면 메인은 공연 예매 포털 (배너 · 장르 · 오픈 예정 · 랭킹). 끄면 다담다 상시 매장 주문 홈
        if (\App\Models\Setting::ticketingEnabled()) {
            return $this->ticketHome();
        }

        $events =Event::with('sections')->where('status', 'scheduled')->where('starts_at', '>', now()->subHours(6))->orderBy('starts_at')->get();

        // 티켓 판매를 쓰면 가장 가까운 공연이 홈의 주인공. 안 쓰면(다담다) 상시 매장 주문이 홈.
        $headliner = \App\Models\Setting::ticketingEnabled() ? $events->first() : null;
        $stores = $headliner ? $this->eventStores($headliner) : $this->openStores();

        // 메인에 보일 메뉴 — 지금 주문할 수 있는(영업 중 매장 · 판매 중 · 품절 아님) 상품 중
        // 최근 30일 실제로 많이 팔린 순 (미결제·취소·환불 제외), 같으면 사진 있는 것 먼저
        $orderable = $headliner ? $stores : $stores->where('is_open', true);
        $candidates = $orderable->flatMap(fn ($s) => $s->products->filter(fn ($p) => ! $p->isSoldOut()));
        $sold = $candidates->isEmpty() ? collect() : \Illuminate\Support\Facades\DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereIn('oi.product_id', $candidates->pluck('id'))
            ->where('o.created_at', '>=', now()->subDays(30))
            ->whereNotIn('o.status', [\App\Enums\OrderStatus::Pending->value, \App\Enums\OrderStatus::Cancelled->value, \App\Enums\OrderStatus::Refunded->value])
            ->groupBy('oi.product_id')->select('oi.product_id')->selectRaw('sum(qty) as qty')->pluck('qty', 'product_id');
        $picks = $candidates->sortByDesc(fn ($p) => [(int) ($sold[$p->id] ?? 0), $p->image_path ? 1 : 0])->take(6)->values();

        return view('fan.home', ['events' => $events, 'headliner' => $headliner, 'stores' => $stores, 'picks' => $picks]);
    }

    /** 공연 메인 — 큰 배너 · 장르 · 오픈 예정 · 장르별 랭킹 · 공연장 */
    private function ticketHome(): View
    {
        $all = $this->showing();
        $sold = $this->recentSales();
        $ranked = $all->sortBy(fn (Production $p) => [-(int) ($sold[$p->id] ?? 0), $p->nextEvent()?->starts_at->timestamp])->values();

        $featured = $all->where('is_featured', true)->sortBy(fn (Production $p) => [$p->sort, $p->nextEvent()?->starts_at->timestamp])->values();

        return view('fan.ticket-home', [
            'hero' => ($featured->isNotEmpty() ? $featured : $ranked)->take(8)->values(),
            'openSoon' => $all->filter(fn (Production $p) => $p->opensLater())->sortBy(fn (Production $p) => $p->salesOpenAt()->timestamp)->values(),
            'ranked' => $ranked,
            'genreCounts' => $all->countBy('genre'),
            'venues' => $all->groupBy('venue')->map->count()->sortDesc()->take(8),
            'storeCount' => Store::whereHas('vendor', fn ($q) => $q->where('state', 'approved'))->count(),
        ]);
    }

    /** 장르별 공연 목록 */
    public function genre(string $genre, Request $request): View
    {
        return $this->showList($request, Production::GENRES[$genre], $this->showing()->where('genre', $genre), $genre);
    }

    /** 공연 검색 — 작품명 · 아티스트 · 공연장 */
    public function search(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $hits = $q === '' ? collect() : $this->showing()->filter(fn (Production $p) => collect([$p->title, $p->artist, $p->venue])
            ->contains(fn ($v) => mb_stripos((string) $v, $q) !== false));

        return $this->showList($request, $q === '' ? '공연 검색' : "‘{$q}’ 검색 결과", $hits, null, $q);
    }

    /** 작품 상세 — 기본 정보·가격·이용안내·환불 규정·장소 + 달력에서 날짜 → 회차 → 등급별 남은 좌석 → 예매 */
    public function production(Production $production): View
    {
        $events = $production->events()->where('status', 'scheduled')->where('starts_at', '>', now())->with('sections')->get();
        abort_if($events->isEmpty(), 404);
        $production->setRelation('events', $events);

        $days = ['일', '월', '화', '수', '목', '금', '토'];
        $tierOrder = fn ($tiers) => $tiers->sortByDesc('price')->values();
        $rounds = $events->map(function (Event $e) use ($days, $tierOrder) {
            $tiers = $tierOrder($e->sections->groupBy('tier')->map(fn ($s, $tier) => [
                'name' => $tier, 'price' => (int) $s->min('price'), 'left' => (int) $s->sum(fn (SeatSection $x) => $x->remaining()),
            ]));

            return [
                'id' => $e->id, 'date' => $e->starts_at->toDateString(), 'time' => $e->starts_at->format('H:i'),
                'label' => $e->starts_at->format('Y.m.d').' ('.$days[$e->starts_at->dayOfWeek].') '.$e->starts_at->format('H:i'),
                'url' => route('concerts.show', $e), 'open' => $e->salesOpen(),
                'opens' => $e->sales_open_at ? $e->sales_open_at->format('n.j').'('.$days[$e->sales_open_at->dayOfWeek].') '.$e->sales_open_at->format('H:i') : null,
                'left' => (int) $tiers->sum('left'), 'tiers' => $tiers->all(),
            ];
        })->values();

        // 가격: 등급별 (회차마다 다르면 범위)
        $prices = $tierOrder($events->flatMap->sections->groupBy('tier')->map(fn ($s, $tier) => [
            'name' => $tier, 'price' => (int) $s->min('price'), 'max' => (int) $s->max('price'),
        ]));

        return view('fan.show', [
            'p' => $production, 'rounds' => $rounds, 'prices' => $prices,
            'venue' => $production->venue_id ? \App\Models\Venue::find($production->venue_id) : null,
            'maxPerBuyer' => (int) ($events->min('max_per_buyer') ?: 4),
            'storesUrl' => route('stores.index', $events->first()),
            'doorsBefore' => $events->first()->doors_at ? $events->first()->doors_at->diffInMinutes($events->first()->starts_at) : null,
        ]);
    }

    private function showList(Request $request, string $title, $items, ?string $genre, string $q = ''): View
    {
        $sort = $request->query('sort') === 'rank' ? 'rank' : 'date';
        $sold = $this->recentSales();
        $items = $sort === 'rank'
            ? $items->sortBy(fn (Production $p) => [-(int) ($sold[$p->id] ?? 0), $p->nextEvent()?->starts_at->timestamp])
            : $items->sortBy(fn (Production $p) => $p->nextEvent()?->starts_at->timestamp);

        return view('fan.shows', ['title' => $title, 'productions' => $items->values(), 'genre' => $genre, 'q' => $q, 'sort' => $sort,
            'genreCounts' => $this->showing()->countBy('genre')]);
    }

    /** 지금 볼 수 있는 작품 (다가오는 회차가 있는) — 회차·구역 가격을 함께 불러온다 */
    private function showing()
    {
        return Production::showing()
            ->with(['events' => fn ($q) => $q->where('status', 'scheduled')->with('sections')])
            ->get();
    }

    /** 최근 14일 작품별 판매 티켓 수 (랭킹) */
    private function recentSales()
    {
        return \Illuminate\Support\Facades\DB::table('tickets')->join('events', 'events.id', '=', 'tickets.event_id')
            ->whereIn('tickets.status', ['paid', 'used'])->where('tickets.created_at', '>=', now()->subDays(14))
            ->groupBy('events.production_id')->select('events.production_id')->selectRaw('count(*) as n')
            ->pluck('n', 'production_id');
    }

    /** 하단 '매장주문' 탭 — 상시 매장 주문 (공연과 상관없이 늘 연다. 원본 다담다 기본 동작) */
    public function orderEntry(): RedirectResponse
    {
        return redirect()->route('shop.index');
    }

    /** 상시 주문 매장 (승인된 업체만, 영업 종료 매장도 보이되 주문은 막힘) */
    private function openStores()
    {
        return Store::whereHas('vendor', fn ($q) => $q->where('state', 'approved'))
            ->with(['products' => fn ($q) => $q->sellable()->orderBy('sort')])
            ->orderByDesc('is_open')->orderBy('id')->get();
    }

    /** 그 공연에서 주문 받는 매장 (승인된 업체만) */
    private function eventStores(Event $event)
    {
        return Store::whereHas('capacitySlots', fn ($q) => $q->where('event_id', $event->id))
            ->whereHas('vendor', fn ($q) => $q->where('state', 'approved'))
            ->with(['capacitySlots' => fn ($q) => $q->where('event_id', $event->id), 'products' => fn ($q) => $q->sellable()->orderBy('sort')])
            ->orderBy('id')->get();
    }

    public function concert(Request $request, Event $event, BenefitService $benefits): View
    {
        $event->load(['sections', 'seatMap']);
        $customer = $this->verifiedCustomer($request);

        return view('fan.concert', [
            'event' => $event,
            'member' => $request->user('member'),
            'customer' => $customer,
            'myCoupons' => $customer ? $benefits->usableCoupons($customer, 'ticket', null, $event) : collect(),
            'freeCoupons' => $this->claimableCoupons($customer, 'ticket', null, $event),
        ]);
    }

    /** 좌석표 공연: 구역의 좌석 격자와 이미 팔린 좌석 ("열-번") */
    public function seats(Event $event, int $section): \Illuminate\Http\JsonResponse
    {
        $s = SeatSection::where('event_id', $event->id)->findOrFail($section);
        abort_unless($s->hasSeatGrid(), 404);

        // 팔린 좌석 + 운영사가 막은 좌석(판매 제외·보류) — 팬에게는 둘 다 '판매됨'으로 보인다
        $unavailable = \Illuminate\Support\Facades\DB::table('taken_seats')->where('seat_section_id', $s->id)->select('row_no', 'seat_no')
            ->union(\Illuminate\Support\Facades\DB::table('seat_blocks')->where('seat_section_id', $s->id)->select('row_no', 'seat_no'))
            ->get()->map(fn ($t) => $t->row_no.'-'.$t->seat_no)->values();

        return response()->json([
            'rows' => $s->rows, 'per_row' => $s->seats_per_row, 'left' => $s->remaining(),
            'layout' => $s->layout,   // 좌석 리스트: 열마다 실제 좌석 번호 [{n,label,seats}] (없으면 격자)
            'taken' => $unavailable,
        ])->header('Cache-Control', 'no-store');
    }

    public function purchase(Request $request, Event $event, TicketService $tickets): RedirectResponse
    {
        $member = $request->user('member');   // 라우트에서 auth:member — 회원만 예매
        $data = $request->validate([
            'section_id' => ['required', 'integer'],
            'qty' => ['required', 'integer', 'min:1', 'max:'.$event->max_per_buyer],
            'agree_terms' => ['accepted'],
            'coupon_issue_id' => ['nullable', 'integer'],
            'mileage_use' => ['nullable', 'integer', 'min:0'],
            'seats' => ['nullable', 'array', 'max:'.$event->max_per_buyer],
            'seats.*' => ['string', 'max:8'],
        ], [
            'agree_terms.accepted' => '이용약관·개인정보 수집·취소 규정에 동의해야 예매할 수 있어요.',
            'section_id.required' => '구역을 먼저 골라 주세요.',
        ]);

        $section = SeatSection::where('event_id', $event->id)->findOrFail($data['section_id']);
        $couponId = $data['coupon_issue_id'] ?? null;
        $mileage = (int) ($data['mileage_use'] ?? 0);

        try {
            // 예매자 = 로그인한 회원 (가입 때 인증한 번호). 회원은 따로 문자 인증 없이 쿠폰·마일리지를 쓴다.
            $bought = $tickets->purchase($event, $section, (int) $data['qty'], $member->name, $member->phone, now(), $couponId, $mileage, $member, $data['seats'] ?? null);
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
        // 회원: 내 계정의 티켓을 모두 보여 준다 (번호 조회 없이)
        if ($member = $request->user('member')) {
            $request->session()->put('ticket_nos', array_values(array_unique(array_merge(
                $request->session()->get('ticket_nos', []), $member->tickets()->pluck('ticket_no')->all()))));
        }
        $phone = '';

        // 취소한 티켓도 내역으로 보여 준다 (취소 수수료·환불액 확인)
        $tickets = Ticket::with(['event', 'section', 'token'])->whereIn('ticket_no', $request->session()->get('ticket_nos', []))
            ->get()->sortBy(fn ($t) => [$t->status === 'cancelled' ? 1 : 0, $t->event->starts_at])->values();

        return view('fan.tickets', ['tickets' => $tickets, 'searched' => $phone !== '']);
    }

    public function stores(Request $request, Event $event): View
    {
        $stores = $this->eventStores($event);

        return view('fan.stores', ['event' => $event, 'stores' => $stores, 'ticket' => $this->ticketFor($request, $event), 'events' => collect()]);
    }

    /** 상시 매장 목록. 오늘 공연이 있으면 '공연 시간대 미리 주문'으로 가는 길도 같이 보인다. */
    public function shop(): View
    {
        $events = Event::where('status', 'scheduled')->whereBetween('starts_at', [now()->subHours(6), now()->endOfDay()])
            ->whereHas('capacitySlots')->orderBy('starts_at')->get();

        return view('fan.stores', ['event' => null, 'stores' => $this->openStores(), 'ticket' => null, 'events' => $events]);
    }

    public function menu(Request $request, Event $event, Store $store, BenefitService $benefits): View
    {
        return $this->menuView($request, $event, $store, $benefits);
    }

    public function shopMenu(Request $request, Store $store, BenefitService $benefits): View
    {
        abort_if($store->vendor_id && ! $store->vendor?->isActive(), 404);

        return $this->menuView($request, null, $store, $benefits);
    }

    private function menuView(Request $request, ?Event $event, Store $store, BenefitService $benefits): View
    {
        $store->load(['vendor', 'products' => fn ($q) => $q->sellable()->with(['options', 'category'])]);
        $store->setRelation('products', \App\Models\Product::menuOrder($store->products));   // 카테고리 순서 → 상품 순서
        $slots = $event ? CapacitySlot::where('event_id', $event->id)->where('store_id', $store->id)
            ->orderByRaw("CASE slot WHEN 'pre' THEN 0 WHEN 'intermission' THEN 1 ELSE 2 END")->get() : collect();
        $customer = $this->verifiedCustomer($request);

        return view('fan.menu', [
            'event' => $event, 'store' => $store, 'slots' => $slots,
            'ticket' => $event ? $this->ticketFor($request, $event) : null,
            'customer' => $customer,
            'myCoupons' => $customer ? $benefits->usableCoupons($customer, 'order', $store, null) : collect(),
            'freeCoupons' => $this->claimableCoupons($customer, 'order', $store, null),
            'stamp' => StampProgram::with('rewardCoupon.product')->where('store_id', $store->id)->where('is_active', true)->first(),
            'stampCount' => $customer ? (int) StampCard::whereHas('program', fn ($q) => $q->where('store_id', $store->id))->where('customer_id', $customer->id)->value('count') : null,
        ]);
    }

    // ───────────────────────── 내 혜택 (휴대폰 인증) ─────────────────────────

    public function benefits(Request $request): View
    {
        $customer = $this->verifiedCustomer($request);
        if (! $customer) {
            return view('fan.benefits', ['customer' => null, 'pendingPhone' => $request->session()->get('pending_phone')]);
        }

        return view('fan.benefits', [
            'customer' => $customer,
            'coupons' => $customer->couponIssues()->with('coupon.product', 'coupon.store', 'coupon.event')->latest()->get(),
            'cards' => $customer->stampCards()->with('program.store', 'program.rewardCoupon.product')->get(),
            'ledger' => $customer->ledger()->where('kind', 'mileage')->latest('id')->limit(30)->get(),
            'freeCoupons' => Coupon::with('store', 'event', 'product')->where('is_public', true)->where('source', 'manual')->get()
                ->filter(fn (Coupon $c) => $c->claimable())
                ->reject(fn (Coupon $c) => CouponIssue::where('coupon_id', $c->id)->where('customer_id', $customer->id)->exists())->values(),
        ]);
    }

    public function sendCode(Request $request, PhoneVerificationService $verify): RedirectResponse
    {
        $phone = $request->validate(['phone' => ['required', 'string', 'max:20']], ['phone.required' => '휴대폰 번호를 적어 주세요.'])['phone'];
        try {
            $code = $verify->send($phone);
        } catch (OrderException $e) {
            return back()->withInput()->withErrors(['phone' => $e->getMessage()]);
        }
        $request->session()->put('pending_phone', Customer::normalize($phone));

        return back()->with('status', '인증번호를 보냈어요. 5분 안에 입력해 주세요.')->with('dev_code', $code);
    }

    public function verifyCode(Request $request, PhoneVerificationService $verify): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string'], 'code' => ['required', 'digits:6']], ['code.digits' => '인증번호 6자리를 적어 주세요.']);
        try {
            $customer = $verify->verify($data['phone'], $data['code']);
        } catch (OrderException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }
        $request->session()->regenerate();
        $request->session()->put('verified_phone', $customer->phone);
        $request->session()->put('verified_until', now()->addMinutes(config('siren.phone_session_minutes'))->timestamp);
        $request->session()->forget('pending_phone');

        $back = $request->input('return');

        return redirect()->to($back && str_starts_with($back, '/') && ! str_starts_with($back, '//') ? $back : route('benefits'))
            ->with('status', '인증했어요. 이제 쿠폰과 마일리지를 쓸 수 있어요.');
    }

    public function forgetPhone(Request $request): RedirectResponse
    {
        $request->session()->forget(['verified_phone', 'verified_until']);

        return redirect()->route('benefits')->with('status', '인증을 해제했어요.');
    }

    public function claimCoupon(Request $request, Coupon $coupon, BenefitService $benefits): RedirectResponse
    {
        $customer = $this->verifiedCustomer($request) ?? throw new OrderException('쿠폰을 받으려면 휴대폰 인증을 먼저 해 주세요.', 'verify_required');
        abort_unless($coupon->is_public, 404);
        $benefits->claim($coupon, $customer);

        return back()->with('status', "‘{$coupon->name}’ 쿠폰을 받았어요.");
    }

    public function claimCode(Request $request, BenefitService $benefits): RedirectResponse
    {
        $customer = $this->verifiedCustomer($request) ?? throw new OrderException('쿠폰을 받으려면 휴대폰 인증을 먼저 해 주세요.', 'verify_required');
        $code = $request->validate(['code' => ['required', 'string', 'max:30']], ['code.required' => '쿠폰 코드를 적어 주세요.'])['code'];
        $issue = $benefits->claimByCode($code, $customer);

        return back()->with('status', "‘{$issue->coupon->name}’ 쿠폰을 받았어요.");
    }

    public function placeOrder(Request $request, Event $event, Store $store, OrderService $orders): JsonResponse
    {
        return $this->orderAt($request, $event, $store, $orders);
    }

    /** 상시 주문 — 공연 시간대(주문 수량 제한) 없이 받는다 */
    public function shopOrder(Request $request, Store $store, OrderService $orders): JsonResponse
    {
        return $this->orderAt($request, null, $store, $orders);
    }

    private function orderAt(Request $request, ?Event $event, Store $store, OrderService $orders): JsonResponse
    {
        $data = $request->validate([
            'slot_id' => [$event ? 'required' : 'nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.option_id' => ['nullable', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.dine_option' => ['nullable', 'in:매장,포장'],
            'agree_terms' => ['accepted'],
            'adult_confirmed' => ['boolean'],
            'coupon_issue_id' => ['nullable', 'integer'],
            'mileage_use' => ['nullable', 'integer', 'min:0'],
        ], ['agree_terms.accepted' => '주문 전에 이용약관과 판매자 정보 제공에 동의해 주세요.']);

        $ticket = $event ? $this->ticketFor($request, $event) : null;
        $couponId = $data['coupon_issue_id'] ?? null;
        $mileage = (int) ($data['mileage_use'] ?? 0);
        $verified = $this->verifiedCustomer($request);
        if (($couponId || $mileage > 0) && ! $verified) {
            throw new OrderException('쿠폰·마일리지를 쓰려면 휴대폰 인증을 먼저 해 주세요.', 'verify_required');
        }
        // 적립 대상: 인증한 번호 > 티켓 예매 번호 (인증 없이도 적립은 받는다 — 쓰려면 인증)
        $customer = $verified ?? ($ticket ? Customer::forPhone($ticket->holder_phone) : null);

        $order = $orders->place($store, $data['items'], 'card', $event ? (int) $data['slot_id'] : null,
            ticket: $ticket,
            adultConfirmed: (bool) ($data['adult_confirmed'] ?? false),
            termsAgreedAt: now(),
            customer: $customer, couponIssueId: $couponId, mileageUse: $mileage);

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

    /** D2 준비 시작 후 취소 요청 → 매장이 승인·거절 */
    public function requestCancel(Request $request, Order $order, OrderService $orders): RedirectResponse
    {
        $this->authorizeOrder($request, $order);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:200']], ['reason.required' => '취소하려는 이유를 적어 주세요.'])['reason'];
        $orders->requestCancel($order, $reason);

        return redirect()->route('orders.show', $order)->with('status', '매장에 취소를 요청했어요. 매장이 확인하면 여기에 결과가 보여요.');
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

    /** 이 세션에서 휴대폰 인증을 마친 고객 (유효 시간 안) */
    private function verifiedCustomer(Request $request): ?Customer
    {
        // 로그인한 회원은 가입 때 인증을 마쳤으므로 회원의 고객 혜택을 그대로 쓴다
        if ($member = $request->user('member')) {
            return $member->customer;
        }
        $phone = $request->session()->get('verified_phone');
        if (! $phone || $request->session()->get('verified_until', 0) < now()->timestamp) {
            return null;
        }

        return Customer::where('phone', $phone)->first();
    }

    /** @return \Illuminate\Support\Collection<int, Coupon> 여기서 받을 수 있는 공개 쿠폰 (이미 받은 것 제외) */
    private function claimableCoupons(?Customer $customer, string $scope, ?Store $store, ?Event $event)
    {
        return Coupon::with('product')->where('is_public', true)->where('source', 'manual')->where('scope', $scope)->get()
            ->filter(fn (Coupon $c) => $c->claimable() && $c->appliesTo($store, $event))
            ->reject(fn (Coupon $c) => $customer && CouponIssue::where('coupon_id', $c->id)->where('customer_id', $customer->id)->exists())
            ->values();
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        abort_unless(in_array($order->order_no, $request->session()->get('order_nos', []), true), 404);
    }
}
