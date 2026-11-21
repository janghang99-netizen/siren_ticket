<?php

namespace App\Http\Controllers;

use App\Models\CapacitySlot;
use App\Models\Event;
use App\Models\Order;
use App\Models\SeatSection;
use App\Models\Settlement;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OrderException;
use App\Services\SettlementService;
use App\Services\TicketService;
use App\Services\VenueSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** 슈퍼어드민(플랫폼 회사) 콘솔 */
class AdminController extends Controller
{
    // ── 공연 · 좌석티켓 ──

    public function events(): View
    {
        $events = Event::with('sections')->withCount(['tickets as sold_count' => fn ($q) => $q->where('status', '!=', 'cancelled')])
            ->withSum(['tickets as revenue' => fn ($q) => $q->where('status', '!=', 'cancelled')], 'price')
            ->orderByRaw('CASE WHEN starts_at < ? THEN 1 ELSE 0 END', [now()])   // 예정 공연 먼저, 지난 공연은 아래로
            ->orderBy('starts_at')->get();

        return view('admin.events', ['events' => $events]);
    }

    public function eventCreate(): View
    {
        return view('admin.event-form', ['event' => null, 'seatMaps' => \App\Models\SeatMap::with('sections')->latest()->get(),
            'venues' => \App\Models\Venue::orderBy('name')->get()]);
    }

    public function eventEdit(Event $event): View
    {
        return view('admin.event-form', ['event' => $event->load(['sections', 'seatMap']), 'seatMaps' => collect(),
            'venues' => \App\Models\Venue::orderBy('name')->get()]);
    }

    public function eventStore(Request $request, VenueSetup $venue, \App\Services\SeatMap\SeatMapService $maps): RedirectResponse
    {
        // 좌석표를 고르면 그 배치도의 구역·좌석으로, 아니면 기본 아레나 보울(등급별 가격·구역당 좌석)
        // 티켓 판매를 끈 경우(다담다 이관 기본): 좌석 없이 공연 일정만 — 매장 주문 시간대만 만든다
        $ticketing = \App\Models\Setting::ticketingEnabled();
        $data = $this->validateEvent($request) + $request->validate([
            'seat_map_id' => ['nullable', 'integer', 'exists:seat_maps,id'],
            'production_id' => ['nullable', 'integer', 'exists:productions,id'],   // 이 작품의 회차로 (없으면 작품을 새로 만든다)
            'tiers' => [$ticketing ? 'required_without:seat_map_id' : 'nullable', 'array'],
            'tiers.*.price' => ['required_with:tiers', 'integer', 'min:0'],
            'tiers.*.capacity' => ['required_with:tiers', 'integer', 'min:1', 'max:5000'],
        ]);
        $seatMap = filled($data['seat_map_id'] ?? null) ? \App\Models\SeatMap::find($data['seat_map_id']) : null;
        if ($seatMap?->venue_id) {
            $data['venue_id'] = $seatMap->venue_id;   // 좌석표의 공연장으로 맞춘다
        }

        $event = DB::transaction(function () use ($data, $venue, $maps, $seatMap) {
            $fields = $this->eventFields($data);
            $productionId = $data['production_id'] ?? \App\Models\Production::create([
                'slug' => Str::lower(Str::random(10)), 'title' => $fields['name'], 'artist' => $fields['artist'],
                'venue' => $fields['venue'], 'venue_id' => $fields['venue_id'], 'theme_color' => $fields['theme_color'],
            ])->id;
            $event = Event::create($fields + [
                'production_id' => $productionId,
                'slug' => Carbon::parse($data['starts_at'])->format('Ymd-Hi').'-'.Str::lower(Str::random(5)),
            ]);
            if ($seatMap) {
                $maps->applyToEvent($event, $seatMap);
            } elseif (! empty($data['tiers'])) {
                $venue->createSections($event, $data['tiers']);
            }
            $venue->ensureSlots($event);

            return $event;
        });
        $sections = $event->sections()->count();
        $seats = (int) $event->sections()->sum('capacity');

        return redirect()->route('admin.events.index')->with('status', $sections
            ? "‘{$event->name}’ 공연을 등록했어요. 좌석 구역 {$sections}개(".number_format($seats).'석)와 매장별 주문 시간대가 함께 만들어졌어요.'
            : "‘{$event->name}’ 공연을 등록했어요. 매장별 주문 시간대가 만들어졌어요.");
    }

    public function eventUpdate(Request $request, Event $event, VenueSetup $venue): RedirectResponse
    {
        $data = $this->validateEvent($request) + $request->validate([
            'sections' => ['array'],
            'sections.*.price' => ['required', 'integer', 'min:0'],
            'sections.*.capacity' => ['required', 'integer', 'min:0', 'max:5000'],
        ]);

        DB::transaction(function () use ($data, $event, $venue) {
            $startsChanged = ! $event->starts_at->equalTo(Carbon::parse($data['starts_at']));
            $event->update($this->eventFields($data));

            foreach ($data['sections'] ?? [] as $id => $s) {
                $section = SeatSection::where('event_id', $event->id)->findOrFail($id);
                if ($section->hasSeatGrid()) {   // 좌석표 구역: 좌석 수 = 열 × 열당 좌석 (가격만 바꾼다)
                    $section->update(['price' => $s['price']]);

                    continue;
                }
                if ((int) $s['capacity'] < $section->sold) {
                    throw new OrderException("{$section->code}구역은 이미 {$section->sold}석이 팔려서 그보다 적게 줄일 수 없어요.");
                }
                $section->update(['price' => $s['price'], 'capacity' => $s['capacity']]);
            }

            if ($startsChanged) {
                $venue->shiftSlotTimes($event->fresh());
            }
        });

        return redirect()->route('admin.events.index')->with('status', "‘{$event->name}’ 공연을 저장했어요.");
    }

    public function tickets(Request $request): View
    {
        $q = Ticket::with(['event', 'section'])
            ->when($request->query('event'), fn ($q, $id) => $q->where('event_id', $id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('holder_name', 'like', "%{$term}%")
                ->orWhere('ticket_no', 'like', "%{$term}%")
                ->when(preg_replace('/\D/', '', $term), fn ($w, $digits) => $w->orWhere('holder_phone', 'like', "%{$digits}%"))));

        return view('admin.tickets', [
            'events' => Event::orderByDesc('starts_at')->get(['id', 'name', 'artist', 'starts_at']),
            'count' => (clone $q)->count(),
            'revenue' => (int) (clone $q)->where('status', '!=', 'cancelled')->sum('price'),
            'tickets' => $q->latest('id')->paginate(40)->withQueryString(),
        ]);
    }

    public function ticketCancel(Ticket $ticket, TicketService $tickets): RedirectResponse
    {
        $tickets->cancel($ticket);
        \App\Services\Audit::log('ticket.cancelled', $ticket, '티켓 '.$ticket->ticket_no.' 운영사 취소 · 전액 환불');

        return back()->with('status', "티켓 {$ticket->ticket_no}을(를) 취소하고 좌석을 다시 판매로 돌렸어요.");
    }

    // ── 입점업체 ──

    public function vendors(): View
    {
        return view('admin.vendors', ['vendors' => Vendor::withCount('stores')->with('users')
            ->orderByRaw("CASE state WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 WHEN 'suspended' THEN 2 ELSE 3 END")
            ->orderBy('id')->get()]);
    }

    public function vendorCreate(): View
    {
        return view('admin.vendor-form', ['vendor' => null, 'defaults' => \App\Models\Setting::values()]);
    }

    public function vendorEdit(Vendor $vendor): View
    {
        return view('admin.vendor-form', ['vendor' => $vendor->load('users', 'stores'), 'defaults' => \App\Models\Setting::values()]);
    }

    public function vendorStore(Request $request): RedirectResponse
    {
        $data = $this->validateVendor($request) + $request->validate([
            'login_email' => ['required', 'email', 'unique:users,email'],
            'login_password' => ['required', 'string', 'min:8'],
        ], [
            'login_email.required' => '업체가 로그인할 이메일을 적어 주세요.',
            'login_email.unique' => '이미 쓰는 로그인 이메일이에요.',
            'login_password.min' => '비밀번호는 8자 이상이어야 해요.',
        ]);

        $vendor = DB::transaction(function () use ($data) {
            // 운영사가 직접 등록한 업체는 계약·서류 확인을 마친 것으로 보고 바로 승인
            $vendor = Vendor::create(collect($data)->except(['login_email', 'login_password'])->all() + ['state' => 'approved', 'approved_at' => now()]);
            User::create([
                'role' => 'vendor', 'vendor_id' => $vendor->id, 'name' => $vendor->name,
                'email' => $data['login_email'], 'password' => $data['login_password'],
            ]);

            return $vendor;
        });

        return redirect()->route('admin.stores.create', ['vendor' => $vendor->id])
            ->with('status', "‘{$vendor->name}’ 업체를 등록했어요. 이어서 첫 매장을 열어 주세요.");
    }

    public function vendorUpdate(Request $request, Vendor $vendor): RedirectResponse
    {
        $vendor->update($this->validateVendor($request));

        return redirect()->route('admin.vendors.show', $vendor)->with('status', "‘{$vendor->name}’ 업체 정보를 저장했어요.");
    }

    // ── 매장 ──

    public function stores(): View
    {
        return view('admin.stores', ['stores' => Store::with('vendor')->withCount('products')->orderBy('vendor_id')->orderBy('id')->get()]);
    }

    public function storeCreate(Request $request): View
    {
        return view('admin.store-form', ['store' => null, 'vendors' => Vendor::orderBy('name')->get(), 'vendorId' => (int) $request->query('vendor')]);
    }

    /** 매장 상세: 운영 현황 · 상품 · 주문 내역 */
    public function storeShow(Request $request, Store $store): View
    {
        $store->load(['vendor', 'products' => fn ($q) => $q->orderBy('sort')->orderBy('id')->with('options')]);

        $from = $request->date('from') ?? now()->subDays(6);
        $to = $request->date('to') ?? now();
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $groups = ['active' => [2, 3, 4], 'done' => [5, 6], 'cancelled' => [11, 14]];

        $base = fn () => Order::where('store_id', $store->id)->whereBetween('created_at', $range);
        $sales = $base()->whereIn('status', [2, 3, 4, 5, 6]);
        $cancels = $base()->whereIn('status', [11, 14]);
        $unsettled = Order::where('store_id', $store->id)->where('status', 5)->whereNull('settlement_id');

        // 가장 가까운 공연의 이 매장 주문 시간대
        $eventId = Event::where('starts_at', '>', now()->subHours(6))->orderBy('starts_at')
            ->whereHas('capacitySlots', fn ($q) => $q->where('store_id', $store->id))->value('id');

        $orders = $base()->with(['store.vendor', 'items', 'ticket.section'])
            ->where('status', '!=', 1)
            ->when($groups[$request->query('group')] ?? null, fn ($q, $s) => $q->whereIn('status', $s))
            ->latest('id')->paginate(30)->withQueryString();

        return view('admin.store-show', [
            'store' => $store,
            'terminals' => DB::table('store_terminals')->where('store_id', $store->id)->orderBy('id')->get(),
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'salesAmount' => (int) (clone $sales)->sum('total_amount'),
            'salesCount' => (clone $sales)->count(),
            'cancelAmount' => (int) (clone $cancels)->sum('total_amount'),
            'cancelCount' => (clone $cancels)->count(),
            'unsettledAmount' => (int) (clone $unsettled)->sum('total_amount'),
            'event' => $eventId ? Event::find($eventId) : null,
            'slots' => CapacitySlot::where('store_id', $store->id)->where('event_id', $eventId)
                ->orderByRaw("CASE slot WHEN 'pre' THEN 0 WHEN 'intermission' THEN 1 ELSE 2 END")->get(),
            'sold' => DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.store_id', $store->id)->whereIn('orders.status', [2, 3, 4, 5, 6])
                ->groupBy('order_items.product_id')->select('order_items.product_id')->selectRaw('SUM(qty) as qty')
                ->pluck('qty', 'product_id'),
            'orders' => $orders,
        ]);
    }

    public function storeEdit(Store $store): View
    {
        return view('admin.store-form', ['store' => $store, 'vendors' => Vendor::orderBy('name')->get(), 'vendorId' => $store->vendor_id]);
    }

    public function storeStore(Request $request, VenueSetup $venue): RedirectResponse
    {
        $store = Store::create($this->validateStore($request));
        $venue->ensureSlots(store: $store);   // 예정된 공연마다 주문 시간대 생성

        return redirect()->route('admin.stores.show', $store->id)->with('status', "‘{$store->name}’ 매장을 열었어요.");
    }

    public function storeUpdate(Request $request, Store $store): RedirectResponse
    {
        $store->update($this->validateStore($request, $store));

        return redirect()->route('admin.stores.show', $store->id)->with('status', "‘{$store->name}’ 매장을 저장했어요.");
    }

    // ── 정산 ──

    public function settlements(Request $request, SettlementService $service, \App\Services\PgSettlementService $pg): View
    {
        $unsettled = Order::where('status', 5)->whereNull('settlement_id');
        $sDate = \App\Services\PgSettlementService::isYmd($request->query('s_date')) ? $request->query('s_date') : null;
        $eDate = \App\Services\PgSettlementService::isYmd($request->query('e_date')) ? $request->query('e_date') : null;

        // 원샷 판매자 정산 대기목록 (원본 go_money.php). 페이레터를 불러온 업체는 카드결제 기준으로 표시만 덮어쓴다
        //  정산예정일이 지난 주문만 대상, 아직 안 된 주문은 노란 상자로 안내 (원본 2026-11-21 #7)
        $pending = $service->pendingByVendor($sDate, $eDate)->map(function (array $r) use ($pg, $sDate, $eDate) {
            [$from, $to] = $pg->pendingWindow($r['vendor'], $sDate, $eDate);
            $r['card'] = $pg->cardSettle($r['vendor'], $from, $to);

            return $r;
        });
        // 원샷 정산 완료목록 (원본 jungsan_list.php) — 정산서마다 같은 규칙의 승인일 기간
        $settlements = Settlement::with('vendor')->latest('id')->paginate(30)->withQueryString();
        $cards = [];
        foreach ($settlements as $s) {
            [$from, $to] = $pg->bundleWindow($s);
            $c = $pg->cardSettle($s->vendor, $from, $to);
            if ($c['ok']) {
                $cards[$s->id] = $c;
            }
        }

        return view('admin.settlements', [
            'settlements' => $settlements,
            'cards' => $cards,
            'pending' => $pending,
            'notDue' => $service->notDueByVendor($sDate, $eDate),
            'dueDays' => (int) \App\Models\Setting::get('settle_due_days'),
            'sDate' => $sDate, 'eDate' => $eDate,
            'importedPending' => (clone $unsettled)->whereNotNull('imported_from')->count(),
            'unsettledCount' => (clone $unsettled)->count(),
            'unsettledAmount' => (int) (clone $unsettled)->sum('total_amount'),
            'oldest' => (clone $unsettled)->min('picked_up_at'),
        ]);
    }

    /** 원샷 판매자 정산 [정산완료] — 그 업체의 수령완료 주문(주문일 기간)을 정산서로 묶는다 (원본 go_money.php mode=seller_reg) */
    public function settlementOneshot(Request $request, Vendor $vendor, SettlementService $service): RedirectResponse
    {
        $data = $request->validate(['s_date' => ['nullable', 'date_format:Y-m-d'], 'e_date' => ['nullable', 'date_format:Y-m-d']]);
        $from = Carbon::parse($data['s_date'] ?? '2000-01-01');
        $to = Carbon::parse($data['e_date'] ?? now()->toDateString());
        $made = $service->generate($from, $to, $vendor->id, 'created_at', dueOnly: true);   // 대기목록에 보인(정산예정일 지난) 주문만
        foreach ($made as $s) {
            \App\Services\Audit::log('settlement.generated', $s, '원샷 정산서 #'.$s->id.' 생성 (주문일 '.$from->toDateString().'~'.$to->toDateString().') 지급액 '.number_format($s->payout_amount).'원');
        }

        return back()->with('status', $made->isEmpty()
            ? "{$vendor->name}: 정산할 수령완료 주문이 없어요."
            : "{$vendor->name} 정산서를 만들었어요. 지급액 ".number_format($made->sum('payout_amount')).'원 — 송금 후 지급 완료로 표시해 주세요.');
    }

    /** 원샷 판매자 정산 대기목록 엑셀 (원본 go_money_excel.php) — 대기목록과 같은 대상(정산예정일 지난 주문)·같은 금액. 계좌가 들어 있어 운영사만 */
    public function settlementOneshotExport(Request $request, SettlementService $service, \App\Services\PgSettlementService $pg): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $sDate = \App\Services\PgSettlementService::isYmd($request->query('s_date')) ? $request->query('s_date') : null;
        $eDate = \App\Services\PgSettlementService::isYmd($request->query('e_date')) ? $request->query('e_date') : null;
        $rows = $service->pendingByVendor($sDate, $eDate)->map(function (array $r) use ($pg, $sDate, $eDate) {
            [$from, $to] = $pg->pendingWindow($r['vendor'], $sDate, $eDate);
            $c = $pg->cardSettle($r['vendor'], $from, $to);
            $v = $r['vendor'];

            return [$v->name, $v->bank_name, $v->bank_account, $v->account_holder, $r['first'].' ~ '.$r['last'], $r['count'],
                $c['ok'] ? $c['card'] : $r['gross'], $r['mileage'] + $r['coupon'],
                $c['ok'] ? $c['fee'] : $r['commission'], $c['ok'] ? $c['vat'] : $r['vat'], $c['ok'] ? $c['pay'] : $r['payout'],
                $c['ok'] ? '카드결제 기준 (승인일 '.$c['from'].' ~ '.$c['to'].')'.($c['short'] ? ' · '.$c['unfinal'].' 이후 카드 결제가 덜 반영됐을 수 있음' : '') : '주문 기준'];
        });
        \App\Services\Audit::log('settlement.oneshot_export', null, '원샷 판매자 정산 엑셀 ('.($sDate ?: '처음').' ~ '.($eDate ?: '오늘').', '.$rows->count().'개 업체)');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['업체', '은행', '계좌번호', '예금주', '주문일', '건수', '총금액', '적립금·업체쿠폰', '수수료(공급가액)', '부가세', '정산금액', '기준']);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, '원샷판매자정산_'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function settlementGenerate(Request $request, SettlementService $service): RedirectResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);
        $made = $service->generate(Carbon::parse($data['from']), Carbon::parse($data['to']));
        foreach ($made as $s) {
            \App\Services\Audit::log('settlement.generated', $s, '정산서 #'.$s->id.' 생성 ('.$data['from'].'~'.$data['to'].') 지급액 '.number_format($s->payout_amount).'원');
        }

        return back()->with('status', $made->isEmpty()
            ? '이 기간에는 정산할 수령완료 주문이 없어요.'
            : "정산서 {$made->count()}건을 만들었어요. 지급액 합계 ".number_format($made->sum('payout_amount')).'원');
    }

    public function settlement(Settlement $settlement, \App\Services\PgSettlementService $pg): View
    {
        [$from, $to] = $pg->bundleWindow($settlement->load('vendor'));

        return view('admin.settlement', [
            'card' => $pg->cardSettle($settlement->vendor, $from, $to),
            'settlement' => $settlement,
            'orders' => $settlement->orders()->with('store', 'items')->orderBy('picked_up_at')->get(),
        ]);
    }

    public function settlementPay(Settlement $settlement, SettlementService $service): RedirectResponse
    {
        $service->markPaid($settlement);
        \App\Services\Audit::log('settlement.paid', $settlement, '정산서 #'.$settlement->id.' '.number_format($settlement->payout_amount).'원 지급 완료');

        return back()->with('status', "{$settlement->vendor->name} 정산 ".number_format($settlement->payout_amount).'원을 지급 완료로 표시했어요.');
    }

    // ── 전체 주문 ──

    public function orders(Request $request): View
    {
        $groups = ['active' => [2, 3, 4], 'done' => [5, 6], 'cancelled' => [11, 14]];
        $from = $request->date('from') ?? now()->subDays(6);
        $to = $request->date('to') ?? now();

        $q = Order::with(['store.vendor', 'items', 'ticket.section'])
            ->where('status', '!=', 1)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($groups[$request->query('group')] ?? null, fn ($q, $s) => $q->whereIn('status', $s))
            ->when($request->query('vendor'), fn ($q, $v) => $q->whereHas('store', fn ($s) => $s->where('vendor_id', $v)));

        return view('admin.orders', [
            'vendors' => Vendor::orderBy('name')->get(['id', 'name']),
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'count' => (clone $q)->count(),
            'total' => (int) (clone $q)->whereNotIn('status', [11, 14])->sum('total_amount'),
            'orders' => $q->latest('id')->paginate(40)->withQueryString(),
        ]);
    }

    // ── 검증 ──

    private function validateEvent(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'artist' => ['required', 'string', 'max:60'],
            'venue_id' => ['nullable', 'integer', 'exists:venues,id'],
            'venue' => ['required_without:venue_id', 'nullable', 'string', 'max:60'],   // 목록에 없는 공연장은 이름으로 (자동 추가)
            'starts_at' => ['required', 'date'],
            'doors_at' => ['nullable', 'date', 'before:starts_at'],
            'sales_open_at' => ['nullable', 'date', 'before:starts_at'],
            'theme_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'max_per_buyer' => ['required', 'integer', 'min:1', 'max:10'],
        ], [
            'name.required' => '공연명을 적어 주세요.', 'artist.required' => '아티스트를 적어 주세요.',
            'starts_at.required' => '공연 시작 일시를 골라 주세요.', 'venue.required_without' => '공연장을 골라 주세요.',
            'doors_at.before' => '입장 시각은 공연 시작보다 빨라야 해요.',
            'sales_open_at.before' => '예매 오픈은 공연 시작보다 빨라야 해요.',
        ]);
    }

    private function eventFields(array $d): array
    {
        // 공연장: 목록에서 고른 공연장(이름은 표시용으로 같이 저장), 이름만 오면 목록에 추가
        $venue = filled($d['venue_id'] ?? null)
            ? \App\Models\Venue::findOrFail($d['venue_id'])
            : \App\Models\Venue::firstOrCreate(['name' => trim((string) $d['venue'])]);

        return collect($d)->only(['name', 'artist', 'starts_at', 'doors_at', 'sales_open_at', 'theme_color', 'max_per_buyer'])->all()
            + ['venue' => $venue->name, 'venue_id' => $venue->id];
    }

    private function validateVendor(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'business_no' => ['nullable', 'string', 'max:20'],
            'mail_order_no' => ['nullable', 'string', 'max:40'],
            'liquor_license_no' => ['nullable', 'string', 'max:40'],
            'representative' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:200'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'contact_email' => ['nullable', 'email'],
            'bank_name' => ['nullable', 'string', 'max:30'],
            'bank_account' => ['nullable', 'string', 'max:40'],
            'account_holder' => ['nullable', 'string', 'max:50'],
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:50'],
            'settlement_cycle' => ['required', 'in:weekly,biweekly,monthly'],
            'settlement_day' => ['required', 'integer', 'min:1', 'max:28'],
            'payout_delay_days' => ['required', 'integer', 'min:0', 'max:30'],
        ], ['name.required' => '업체명을 적어 주세요.']);
    }

    // ── 상세 화면 · 정책 조치 ──

    public function vendor(Vendor $vendor): View
    {
        $vendor->load(['users', 'stores' => fn ($q) => $q->withCount('products')]);
        $storeIds = $vendor->stores->pluck('id');

        return view('admin.vendor', [
            'vendor' => $vendor,
            'settlements' => $vendor->settlements()->latest('id')->limit(10)->get(),
            'pendingAdjustments' => $vendor->adjustments()->whereNull('settlement_id')->latest('id')->get(),
            'salesAmount' => (int) Order::whereIn('store_id', $storeIds)->whereIn('status', [2, 3, 4, 5, 6])->where('created_at', '>=', now()->subDays(30))->sum('total_amount'),
            'unsettledAmount' => (int) Order::whereIn('store_id', $storeIds)->where('status', 5)->whereNull('settlement_id')->sum('total_amount'),
            'integration' => DB::table('vendor_integrations')->where('vendor_id', $vendor->id)->first(),
            'integrationLogs' => DB::table('integration_logs')->where('vendor_id', $vendor->id)->latest('id')->limit(8)->get()
                ->concat(DB::table('webhook_deliveries')->join('orders', 'orders.id', '=', 'webhook_deliveries.order_id')
                    ->whereIn('orders.store_id', $storeIds)->select('webhook_deliveries.*', DB::raw("'order.completed' as type"))
                    ->latest('webhook_deliveries.id')->limit(8)->get())
                ->sortByDesc('created_at')->take(10),
            'nextSettlement' => $vendor->nextSettlementDate(($last = $vendor->settlements()->max('period_end')) ? Carbon::parse($last) : null),
        ]);
    }

    /** A1 승인·반려 / A4 정지·재개 (정지하면 그 업체 매장은 모두 영업 종료) */
    public function vendorState(Request $request, Vendor $vendor): RedirectResponse
    {
        $data = $request->validate([
            'state' => ['required', 'in:approved,rejected,suspended'],
            'reason' => ['nullable', 'required_if:state,rejected,suspended', 'string', 'max:200'],
        ], ['reason.required_if' => '반려·정지 사유를 적어 주세요. 업체에 그대로 보여요.']);

        DB::transaction(function () use ($vendor, $data) {
            $vendor->update([
                'state' => $data['state'],
                'state_reason' => $data['state'] === 'approved' ? null : $data['reason'],
                'approved_at' => $data['state'] === 'approved' ? ($vendor->approved_at ?? now()) : $vendor->approved_at,
            ]);
            if ($data['state'] === 'suspended') {
                $vendor->stores()->update(['is_open' => false]);
            }
        });

        \App\Services\Audit::log('vendor.'.$data['state'], $vendor, $vendor->name.' '.['approved' => '승인', 'rejected' => '반려', 'suspended' => '정지'][$data['state']].(empty($data['reason']) ? '' : ': '.$data['reason']));
        $label = ['approved' => '승인했어요', 'rejected' => '반려했어요', 'suspended' => '이용 정지했어요. 매장은 모두 영업 종료로 바뀌었어요'][$data['state']];

        return back()->with('status', "‘{$vendor->name}’ 업체를 {$label}.");
    }

    public function vendorDocument(Vendor $vendor, string $type)
    {
        $path = $vendor->documents[$type] ?? null;
        abort_unless($path && array_key_exists($type, Vendor::DOCUMENTS) && \Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->download($path, $vendor->name.'_'.Vendor::DOCUMENTS[$type].'.'.pathinfo($path, PATHINFO_EXTENSION));
    }

    /** B1 운영사 판매 중지(제재) / 해제 */
    public function productBlock(Request $request, \App\Models\Product $product): RedirectResponse
    {
        if ($product->isBlocked()) {
            $product->update(['blocked_at' => null, 'block_reason' => null]);
            \App\Services\Audit::log('product.unblocked', $product, '‘'.$product->name.'’ 판매 중지 해제');

            return back()->with('status', "‘{$product->name}’ 판매 중지를 풀었어요.");
        }
        $reason = $request->validate(['reason' => ['required', 'string', 'max:200']], ['reason.required' => '판매 중지 사유를 적어 주세요. 업체에 그대로 보여요.'])['reason'];
        $product->update(['blocked_at' => now(), 'block_reason' => $reason]);
        \App\Services\Audit::log('product.blocked', $product, '‘'.$product->name.'’ 판매 중지: '.$reason);

        return back()->with('status', "‘{$product->name}’을(를) 판매 중지했어요.");
    }

    public function order(Order $order): View
    {
        return view('admin.order', [
            'order' => $order->load(['store.vendor', 'items', 'ticket.section', 'ticket.event', 'capacitySlot', 'settlement']),
            'webhooks' => DB::table('webhook_deliveries')->where('order_id', $order->id)->latest('id')->get(),
            'movements' => \App\Models\StockMovement::with(['product', 'option'])->where('order_id', $order->id)->orderBy('id')->get(),
        ]);
    }

    /** 수령완료·정산완료 주문 환불 (정산완료면 차기 정산 공제 생성) */
    public function orderRefund(Request $request, Order $order, \App\Services\OrderService $orders): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:100']], ['reason.required' => '환불 사유를 적어 주세요.'])['reason'];
        $was = $order->status;
        $orders->adminRefund($order, $reason);
        \App\Services\Audit::log('order.refunded', $order, '주문 '.$order->pickup_code.' '.number_format($order->total_amount).'원 환불: '.$reason.($was === \App\Enums\OrderStatus::Settled ? ' (차기 정산 공제)' : ''));

        return back()->with('status', $was === \App\Enums\OrderStatus::Settled
            ? '환불했어요. 이미 정산된 주문이라 업체의 다음 정산에서 공제돼요.'
            : '환불했어요. 정산 대상에서 빠졌어요.');
    }

    public function ticket(Ticket $ticket): View
    {
        $ticket->load(['event', 'section']);

        return view('admin.ticket', [
            'ticket' => $ticket,
            'orders' => $ticket->orders()->with(['store', 'items'])->latest('id')->get(),
            'others' => Ticket::with('section')->where('event_id', $ticket->event_id)->where('holder_phone', $ticket->holder_phone)->whereKeyNot($ticket->id)->get(),
            'feeRate' => $ticket->status === 'paid' ? $ticket->cancelFeeRate() : null,
        ]);
    }

    public function settlementTaxInvoice(Settlement $settlement, SettlementService $service): RedirectResponse
    {
        $service->markTaxInvoice($settlement);
        \App\Services\Audit::log('settlement.tax_invoice', $settlement, '정산서 #'.$settlement->id.' 세금계산서 '.($settlement->tax_invoice_issued_at ? '발행 표시' : '표시 해제'));

        return back()->with('status', $settlement->tax_invoice_issued_at ? '세금계산서 발행 완료로 표시했어요.' : '세금계산서 발행 표시를 지웠어요.');
    }

    public function settlementReply(Request $request, Settlement $settlement, SettlementService $service): RedirectResponse
    {
        $reply = $request->validate(['reply' => ['required', 'string', 'max:2000']], ['reply.required' => '답변 내용을 적어 주세요.'])['reply'];
        $service->replyDispute($settlement, $reply);
        \App\Services\Audit::log('settlement.replied', $settlement, '정산서 #'.$settlement->id.' 이의제기 답변');

        return back()->with('status', '이의제기에 답변했어요. 업체 정산 화면에 보여요.');
    }

    // ── 2단계: 공연 취소 · 공제 등록 · 감사 로그 ──

    /** F4 공연 취소 → 티켓 전액 일괄 환불 + 매장 주문 환불 + 주문 시간대 마감 */
    public function eventCancel(Request $request, Event $event, TicketService $tickets, \App\Services\OrderService $orders): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:200'],
            'confirm_name' => ['required', 'in:'.$event->artist],
        ], ['reason.required' => '취소 사유를 적어 주세요. 예매자에게 보여요.', 'confirm_name.in' => '확인을 위해 아티스트 이름을 정확히 적어 주세요.']);

        $r = DB::transaction(fn () => $tickets->cancelEvent($event, $data['reason'], $orders));
        \App\Services\Audit::log('event.cancelled', $event, "공연 취소 ‘{$event->name}’: {$data['reason']} · 티켓 {$r['tickets']}매 ".number_format($r['refund'])."원 환불 · 매장 주문 {$r['orders']}건 환불", $r);

        return redirect()->route('admin.events.index')->with('status', "공연을 취소했어요. 티켓 {$r['tickets']}매(".number_format($r['refund'])."원)를 전액 환불하고, 매장 주문 {$r['orders']}건을 환불했어요.");
    }

    /** C7 판매자 귀책 비용 등 공제 항목 등록 → 다음 정산에서 차감 */
    public function adjustmentStore(Request $request, Vendor $vendor): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reason' => ['required', 'string', 'max:200'],
        ], ['amount.required' => '공제 금액을 적어 주세요.', 'reason.required' => '공제 사유를 적어 주세요. 업체 정산서에 그대로 보여요.']);

        $adj = \App\Models\SettlementAdjustment::create(['vendor_id' => $vendor->id] + $data);
        \App\Services\Audit::log('adjustment.created', $vendor, "{$vendor->name} 공제 ".number_format($adj->amount)."원 등록: {$adj->reason}");

        return back()->with('status', number_format($adj->amount).'원을 다음 정산에서 공제하도록 등록했어요.');
    }

    /** 업체 단위 EIS 연동 설정 (원본 admin/seller_webhook.php): EIS 코드·주문 URL·생산 URL·서명 키 */
    public function integrationSave(Request $request, Vendor $vendor): RedirectResponse
    {
        $current = DB::table('vendor_integrations')->where('vendor_id', $vendor->id)->first();
        $data = $request->validate([
            'eis_code' => ['required', 'alpha_dash', 'max:40', Rule::unique('vendor_integrations', 'eis_code')->ignore($current?->id)],
            'order_url' => ['nullable', 'url', 'max:255'],
            'production_url' => ['nullable', 'url', 'max:255'],
        ], ['eis_code.required' => 'EIS에서 쓰는 업체 코드(seller_id)를 적어 주세요.', 'eis_code.unique' => '다른 업체가 쓰는 EIS 코드예요.']);

        $regen = ! $current || $request->boolean('regenerate_secret');
        $secret = $regen ? Str::random(40) : $current->secret;
        DB::table('vendor_integrations')->updateOrInsert(['vendor_id' => $vendor->id], [
            'eis_code' => $data['eis_code'], 'order_url' => $data['order_url'] ?? '', 'production_url' => $data['production_url'] ?? '',
            'secret' => $secret, 'is_active' => $request->boolean('is_active'), 'updated_at' => now(), 'created_at' => $current?->created_at ?? now(),
        ]);
        \App\Services\Audit::log('settings.saved', $vendor, "{$vendor->name} EIS 연동 설정 저장".($regen ? ' · 서명 키 새로 발급' : ''));

        return back()->with('status', 'EIS 연동을 저장했어요.'.($regen ? ' 새 서명 키를 EIS 담당자에게 전달해 주세요.' : ''));
    }

    /** G1 감사 로그 */
    public function audit(Request $request): View
    {
        $logs = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->select('audit_logs.*', 'users.name as user_name', 'users.email as user_email')
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', $a))
            ->when($request->query('q'), fn ($q, $t) => $q->where('summary', 'like', "%{$t}%"))
            ->orderByDesc('audit_logs.id')->paginate(50)->withQueryString();

        return view('admin.audit', ['logs' => $logs]);
    }

    // ── 수수료 · 정산주기 설정 (C2·C3) ──

    public function settings(): View
    {
        return view('admin.settings', [
            'settings' => \App\Models\Setting::values(),
            'vendors' => Vendor::with(['stores' => fn ($q) => $q->orderBy('id')])->orderBy('id')->get(),
        ]);
    }

    /** 공연 티켓 판매 기능 켜기/끄기 — 끄면 예매·내 티켓·공연장 좌석표가 숨고, 공연 일정·매장 주문은 그대로 */
    public function ticketingToggle(Request $request): RedirectResponse
    {
        $on = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        \App\Models\Setting::put(['ticketing_enabled' => $on ? '1' : '0']);
        \App\Services\Audit::log('settings.ticketing', null, '공연 티켓 판매 기능 '.($on ? '켬' : '끔'));

        return back()->with('status', $on
            ? '공연 티켓 판매 기능을 켰어요. 팬 화면에 좌석 예매·내 티켓이 다시 보여요.'
            : '공연 티켓 판매 기능을 껐어요. 팬 화면에서 예매·내 티켓이 숨고, 매장 주문은 그대로예요. (데이터는 지우지 않아요)');
    }

    /** 화면 이름 · 운영사 사업자 정보 (팬 화면 하단 고지) */
    public function siteSave(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'brand_name' => ['required', 'string', 'max:20'],
            'company_name' => ['required', 'string', 'max:60'],
            'company_ceo' => ['nullable', 'string', 'max:40'],
            'company_reg_no' => ['nullable', 'string', 'max:20'],
            'company_mail_order_no' => ['nullable', 'string', 'max:40'],
            'company_address' => ['nullable', 'string', 'max:160'],
            'company_phone' => ['nullable', 'string', 'max:30'],
            'company_email' => ['nullable', 'email', 'max:120'],
        ], ['brand_name.required' => '화면 이름을 적어 주세요.', 'company_name.required' => '상호를 적어 주세요.']);
        \App\Models\Setting::put(array_map(fn ($v) => (string) $v, $data));
        \App\Services\Audit::log('settings.site', null, '화면 이름·사업자 정보 저장');

        return back()->with('status', '화면 이름·사업자 정보를 저장했어요. 팬 화면 하단에 바로 보여요.');
    }

    public function settingsSave(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'defaults.default_commission_rate' => ['required', 'numeric', 'min:0', 'max:50'],
            'defaults.default_settlement_cycle' => ['required', 'in:weekly,biweekly,monthly'],
            'defaults.default_settlement_day' => ['required', 'integer', 'min:1', 'max:28'],
            'defaults.default_payout_delay_days' => ['required', 'integer', 'min:0', 'max:30'],
            'defaults.commission_vat_rate' => ['required', 'numeric', 'min:0', 'max:20'],
            'defaults.settle_due_days' => ['sometimes', 'integer', 'min:0', 'max:60'],   // 상품판매 정산예정일 (원샷 판매자 정산)
            'defaults.mileage_min_use' => ['required', 'integer', 'min:0', 'max:100000'],
            'defaults.mileage_use_max_rate' => ['required', 'integer', 'min:0', 'max:100'],
            'defaults.mileage_product_max_rate' => ['required', 'integer', 'min:0', 'max:50'],
            'defaults.ticket_mileage_rate' => ['required', 'numeric', 'min:0', 'max:20'],
            'vendors' => ['array'],
            'vendors.*.commission_rate' => ['required', 'numeric', 'min:0', 'max:50'],
            'vendors.*.settlement_cycle' => ['required', 'in:weekly,biweekly,monthly'],
            'vendors.*.settlement_day' => ['required', 'integer', 'min:1', 'max:28'],
            'vendors.*.payout_delay_days' => ['required', 'integer', 'min:0', 'max:30'],
            'stores' => ['array'],
            'stores.*.commission_rate' => ['required', 'numeric', 'min:0', 'max:50'],
        ]);

        DB::transaction(function () use ($data) {
            \App\Models\Setting::put($data['defaults']);
            foreach ($data['vendors'] ?? [] as $id => $v) {
                Vendor::whereKey($id)->update($v);
            }
            foreach ($data['stores'] ?? [] as $id => $s) {
                Store::whereKey($id)->update(['commission_rate' => $s['commission_rate']]);
            }
        });

        \App\Services\Audit::log('settings.saved', null, '수수료·정산 설정 저장', $data['defaults']);

        return back()->with('status', '설정을 저장했어요. 바뀐 수수료율은 이후 주문부터, 정산주기는 다음 정산부터 적용돼요.');
    }

    private function validateStore(Request $request, ?Store $store = null): array
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'exists:vendors,id'],
            'code' => ['required', 'alpha_dash', 'max:30', Rule::unique('stores', 'code')->ignore($store?->id)],
            'name' => ['required', 'string', 'max:60'],
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:50'],
            'area' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:160'],
            'hours' => ['nullable', 'string', 'max:120'],
            'holidays' => ['nullable', 'string', 'max:120'],
        ], [
            'vendor_id.required' => '운영 업체를 골라 주세요.',
            'code.required' => '매장 코드를 적어 주세요.',
            'code.unique' => '이미 쓰는 매장 코드예요.',
            'code.alpha_dash' => '매장 코드는 영문·숫자·-·_ 만 쓸 수 있어요.',
            'name.required' => '매장 이름을 적어 주세요.',
        ]);

        return $data + ['is_open' => $request->boolean('is_open')];
    }
}
