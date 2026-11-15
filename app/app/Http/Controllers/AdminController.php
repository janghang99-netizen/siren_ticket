<?php

namespace App\Http\Controllers;

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
        return view('admin.event-form', ['event' => null]);
    }

    public function eventEdit(Event $event): View
    {
        return view('admin.event-form', ['event' => $event->load('sections')]);
    }

    public function eventStore(Request $request, VenueSetup $venue): RedirectResponse
    {
        $data = $this->validateEvent($request) + $request->validate([
            'tiers' => ['required', 'array'],
            'tiers.*.price' => ['required', 'integer', 'min:0'],
            'tiers.*.capacity' => ['required', 'integer', 'min:1', 'max:5000'],
        ]);

        $event = DB::transaction(function () use ($data, $venue) {
            $event = Event::create($this->eventFields($data) + [
                'slug' => Carbon::parse($data['starts_at'])->format('Ymd-Hi').'-'.Str::lower(Str::random(5)),
            ]);
            $venue->createSections($event, $data['tiers']);
            $venue->ensureSlots($event);

            return $event;
        });

        return redirect()->route('admin.events.index')->with('status', "‘{$event->name}’ 공연을 등록했어요. 좌석 구역 17개와 매장별 주문 시간대가 함께 만들어졌어요.");
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

        return back()->with('status', "티켓 {$ticket->ticket_no}을(를) 취소하고 좌석을 다시 판매로 돌렸어요.");
    }

    // ── 입점업체 ──

    public function vendors(): View
    {
        return view('admin.vendors', ['vendors' => Vendor::withCount('stores')->with('users')->orderBy('id')->get()]);
    }

    public function vendorCreate(): View
    {
        return view('admin.vendor-form', ['vendor' => null]);
    }

    public function vendorEdit(Vendor $vendor): View
    {
        return view('admin.vendor-form', ['vendor' => $vendor->load('users', 'stores')]);
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
            $vendor = Vendor::create(collect($data)->except(['login_email', 'login_password'])->all());
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

        return redirect()->route('admin.vendors.index')->with('status', "‘{$vendor->name}’ 업체 정보를 저장했어요.");
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

    public function storeEdit(Store $store): View
    {
        return view('admin.store-form', ['store' => $store, 'vendors' => Vendor::orderBy('name')->get(), 'vendorId' => $store->vendor_id]);
    }

    public function storeStore(Request $request, VenueSetup $venue): RedirectResponse
    {
        $store = Store::create($this->validateStore($request));
        $venue->ensureSlots(store: $store);   // 예정된 공연마다 주문 시간대 생성

        return redirect()->route('admin.stores.index')->with('status', "‘{$store->name}’ 매장을 열었어요.");
    }

    public function storeUpdate(Request $request, Store $store): RedirectResponse
    {
        $store->update($this->validateStore($request, $store));

        return redirect()->route('admin.stores.index')->with('status', "‘{$store->name}’ 매장을 저장했어요.");
    }

    // ── 정산 ──

    public function settlements(): View
    {
        $unsettled = Order::where('status', 5)->whereNull('settlement_id');

        return view('admin.settlements', [
            'settlements' => Settlement::with('vendor')->latest('id')->paginate(30),
            'unsettledCount' => (clone $unsettled)->count(),
            'unsettledAmount' => (int) (clone $unsettled)->sum('total_amount'),
            'oldest' => (clone $unsettled)->min('picked_up_at'),
        ]);
    }

    public function settlementGenerate(Request $request, SettlementService $service): RedirectResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);
        $made = $service->generate(Carbon::parse($data['from']), Carbon::parse($data['to']));

        return back()->with('status', $made->isEmpty()
            ? '이 기간에는 정산할 수령완료 주문이 없어요.'
            : "정산서 {$made->count()}건을 만들었어요. 지급액 합계 ".number_format($made->sum('payout_amount')).'원');
    }

    public function settlement(Settlement $settlement): View
    {
        return view('admin.settlement', [
            'settlement' => $settlement->load('vendor'),
            'orders' => $settlement->orders()->with('store', 'items')->orderBy('picked_up_at')->get(),
        ]);
    }

    public function settlementPay(Settlement $settlement, SettlementService $service): RedirectResponse
    {
        $service->markPaid($settlement);

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
            'venue' => ['required', 'string', 'max:60'],
            'starts_at' => ['required', 'date'],
            'doors_at' => ['nullable', 'date', 'before:starts_at'],
            'sales_open_at' => ['nullable', 'date', 'before:starts_at'],
            'theme_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'max_per_buyer' => ['required', 'integer', 'min:1', 'max:10'],
        ], [
            'name.required' => '공연명을 적어 주세요.', 'artist.required' => '아티스트를 적어 주세요.',
            'starts_at.required' => '공연 시작 일시를 골라 주세요.',
            'doors_at.before' => '입장 시각은 공연 시작보다 빨라야 해요.',
            'sales_open_at.before' => '예매 오픈은 공연 시작보다 빨라야 해요.',
        ]);
    }

    private function eventFields(array $d): array
    {
        return collect($d)->only(['name', 'artist', 'venue', 'starts_at', 'doors_at', 'sales_open_at', 'theme_color', 'max_per_buyer'])->all();
    }

    private function validateVendor(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'business_no' => ['nullable', 'string', 'max:20'],
            'representative' => ['nullable', 'string', 'max:30'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'contact_email' => ['nullable', 'email'],
            'bank_name' => ['nullable', 'string', 'max:30'],
            'bank_account' => ['nullable', 'string', 'max:40'],
            'account_holder' => ['nullable', 'string', 'max:50'],
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:50'],
            'status' => ['required', 'in:active,suspended'],
        ], ['name.required' => '업체명을 적어 주세요.']);
    }

    private function validateStore(Request $request, ?Store $store = null): array
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'exists:vendors,id'],
            'code' => ['required', 'alpha_dash', 'max:30', Rule::unique('stores', 'code')->ignore($store?->id)],
            'name' => ['required', 'string', 'max:60'],
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:50'],
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
