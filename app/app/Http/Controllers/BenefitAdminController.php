<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SavesCoupons;
use App\Models\BenefitLedger;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Event;
use App\Models\LedgerAnchor;
use App\Models\Product;
use App\Models\CouponIssue;
use App\Models\StampCard;
use App\Models\StampProgram;
use App\Models\Store;
use App\Services\Audit;
use App\Services\Benefits\BenefitLedgerService;
use App\Services\Benefits\BenefitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** 운영사 혜택 관리: 쿠폰 · 고객(마일리지) · 관람 스탬프 · 혜택 원장(해시 체인·앵커) */
class BenefitAdminController extends Controller
{
    use SavesCoupons;

    // ── 쿠폰 ──

    public function coupons(Request $request): View
    {
        $coupons = Coupon::with(['vendor', 'store', 'event', 'product'])
            ->when($request->query('issuer'), fn ($q, $i) => $q->where('issuer', $i))
            ->when($request->query('scope'), fn ($q, $s) => $q->where('scope', $s))
            ->latest('id')->paginate(30)->withQueryString();
        $program = StampProgram::with('rewardCoupon')->where('owner', 'platform')->first();

        return view('admin.coupons', ['coupons' => $coupons, 'program' => $program]);
    }

    public function couponCreate(): View
    {
        return $this->couponForm(new Coupon(['scope' => 'order', 'kind' => 'fixed', 'is_public' => true, 'is_active' => true]));
    }

    public function couponEdit(Coupon $coupon): View
    {
        abort_unless($coupon->issuer === 'platform', 403, '업체 쿠폰은 업체가 관리해요.');

        return $this->couponForm($coupon);
    }

    public function couponStore(Request $request): RedirectResponse
    {
        $coupon = Coupon::create($this->couponData($request, null, Store::pluck('id')->all(), true) + ['issuer' => 'platform']);
        Audit::log('coupon.created', $coupon, "운영사 쿠폰 등록: {$coupon->name}");

        return redirect()->route('admin.coupons.show', $coupon)->with('status', '쿠폰을 만들었어요.');
    }

    public function couponUpdate(Request $request, Coupon $coupon): RedirectResponse
    {
        abort_unless($coupon->issuer === 'platform', 403);
        $coupon->update($this->couponData($request, $coupon, Store::pluck('id')->all(), true));
        Audit::log('coupon.updated', $coupon, "운영사 쿠폰 수정: {$coupon->name}");

        return redirect()->route('admin.coupons.show', $coupon)->with('status', '쿠폰을 고쳤어요. 이미 받은 쿠폰에도 바뀐 조건이 적용돼요.');
    }

    public function coupon(Coupon $coupon): View
    {
        return view('admin.coupon', [
            'coupon' => $coupon->load('vendor', 'store', 'event', 'product'),
            'issues' => $coupon->issues()->with('customer')->latest('id')->paginate(30),
        ]);
    }

    /** CS 보상 등: 휴대폰 번호로 직접 지급 */
    public function couponGrant(Request $request, Coupon $coupon, BenefitService $benefits): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'regex:/^01[0-9][- ]?\d{3,4}[- ]?\d{4}$/'],
            'memo' => ['required', 'string', 'max:100'],
        ], ['phone.regex' => '휴대폰 번호를 010-1234-5678 형식으로 적어 주세요.', 'memo.required' => '지급 사유를 적어 주세요.']);
        $customer = Customer::forPhone($data['phone']);
        $benefits->grant($coupon, $customer, $data['memo'], $request->user()->id);
        Audit::log('coupon.granted', $coupon, "쿠폰 직접 지급: {$coupon->name} → {$customer->maskedPhone()}", ['memo' => $data['memo']]);

        return back()->with('status', "{$customer->maskedPhone()}에게 쿠폰을 줬어요.");
    }

    // ── 관람 스탬프 (운영사 프로그램) ──

    public function stampSave(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'need_count' => ['required', 'integer', 'min:1', 'max:50'],
            'reward_value' => ['required', 'integer', 'min:100'],
            'reward_days' => ['required', 'integer', 'min:1', 'max:365'],
        ], ['reward_value.min' => '보상 쿠폰 금액은 100원 이상이어야 해요.']);

        $program = StampProgram::firstOrNew(['owner' => 'platform']);
        $reward = $program->rewardCoupon ?? new Coupon(['issuer' => 'platform', 'scope' => 'ticket', 'kind' => 'fixed', 'source' => 'stamp']);
        $reward->fill(['name' => '관람 스탬프 보상', 'value' => $data['reward_value'], 'valid_days' => $data['reward_days'], 'is_active' => true])->save();
        $program->fill([
            'name' => '공연 관람 스탬프', 'need_count' => $data['need_count'], 'is_active' => $request->boolean('is_active'),
            'reward_coupon_id' => $reward->id,
        ])->save();
        Audit::log('stamp.saved', $program, "관람 스탬프 설정: {$data['need_count']}개 → 티켓 ".number_format($data['reward_value']).'원 할인');

        return back()->with('status', '관람 스탬프 설정을 저장했어요.');
    }

    /**
     * 회원별 스탬프 현황 (원본 admin/stamp_status.php 회원별 탭).
     * 카드 한 장 = 고객 한 명 × 스탬프 프로그램 하나. 회원 이름·휴대폰으로, 매장(또는 관람 스탬프)으로 거른다.
     */
    public function stampCards(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $digits = preg_replace('/\D/', '', $q);
        $store = (string) $request->query('store');

        $cards = StampCard::query()->select('stamp_cards.*')
            // 받은 무료쿠폰 수: 이 프로그램의 보상 쿠폰을 이 고객이 받은 장수
            ->addSelect(['rewards_count' => CouponIssue::selectRaw('count(*)')
                ->whereColumn('coupon_issues.customer_id', 'stamp_cards.customer_id')
                ->whereIn('coupon_issues.coupon_id', StampProgram::select('reward_coupon_id')->whereColumn('stamp_programs.id', 'stamp_cards.stamp_program_id')),
            ])
            // 최근 적립일: 혜택 원장의 스탬프 적립(earn) 마지막 시각
            ->addSelect(['last_earned_at' => BenefitLedger::selectRaw('max(created_at)')
                ->whereColumn('benefit_ledger.customer_id', 'stamp_cards.customer_id')
                ->whereColumn('benefit_ledger.ref_id', 'stamp_cards.stamp_program_id')
                ->where('benefit_ledger.kind', 'stamp')->where('benefit_ledger.type', 'earn'),
            ])
            ->with(['customer.member', 'program.store'])
            ->when($q !== '', fn ($query) => $query->whereHas('customer', fn ($c) => $c->where(fn ($w) => $w
                ->whereHas('member', fn ($m) => $m->where('name', 'like', '%'.$q.'%'))
                ->when($digits !== '', fn ($w) => $w->orWhere('phone', 'like', '%'.$digits.'%')))))
            ->when($store === 'platform', fn ($query) => $query->whereHas('program', fn ($p) => $p->where('owner', 'platform')))
            ->when(ctype_digit($store), fn ($query) => $query->whereHas('program', fn ($p) => $p->where('store_id', (int) $store)))
            ->orderByDesc('stamp_cards.updated_at')->orderByDesc('stamp_cards.id')
            ->paginate(30)->withQueryString();

        return view('admin.stamp-cards', [
            'cards' => $cards,
            'stores' => Store::whereIn('id', StampProgram::whereNotNull('store_id')->select('store_id'))->orderBy('name')->get(['id', 'name']),
            'hasPlatform' => StampProgram::where('owner', 'platform')->exists(),
            'cardCount' => StampCard::count(),
            'customerCount' => StampCard::distinct()->count('customer_id'),
        ]);
    }

    // ── 고객 (휴대폰 번호) ──

    public function customers(Request $request): View
    {
        $q = preg_replace('/\D/', '', (string) $request->query('q'));
        $customers = Customer::withCount(['couponIssues as coupons_count' => fn ($q) => $q->where('status', 'issued')])
            ->when($q !== '', fn ($query) => $query->where('phone', 'like', "%{$q}%"))
            ->orderByDesc('mileage_balance')->orderByDesc('id')->paginate(30)->withQueryString();

        return view('admin.customers', [
            'customers' => $customers,
            'totalMileage' => (int) Customer::sum('mileage_balance'),
            'count' => Customer::count(),
        ]);
    }

    public function customer(Customer $customer): View
    {
        return view('admin.customer', [
            'customer' => $customer,
            'ledger' => $customer->ledger()->latest('id')->paginate(30),
            'coupons' => $customer->couponIssues()->with('coupon')->latest('id')->get(),
            'cards' => $customer->stampCards()->with('program.store')->get(),
        ]);
    }

    public function customerAdjust(Request $request, Customer $customer, BenefitService $benefits): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'memo' => ['required', 'string', 'max:100'],
        ], ['amount.not_in' => '0원은 조정할 수 없어요.', 'memo.required' => '조정 사유를 적어 주세요.']);
        $benefits->adjustMileage($customer, (int) $data['amount'], $data['memo'], $request->user()->id);
        Audit::log('mileage.adjusted', $customer, "마일리지 수동 조정 {$customer->maskedPhone()} ".($data['amount'] > 0 ? '+' : '').number_format($data['amount']).'원', ['memo' => $data['memo']]);

        return back()->with('status', '마일리지를 조정했어요.');
    }

    // ── 혜택 원장 (해시 체인 · 블록체인 앵커) ──

    public function ledger(BenefitLedgerService $ledger): View
    {
        return view('admin.ledger', [
            'check' => $ledger->verify(),
            'anchors' => LedgerAnchor::latest('id')->paginate(20),
            'pending' => BenefitLedger::whereNull('anchor_id')->count(),
            'rows' => BenefitLedger::with('customer')->latest('id')->limit(30)->get(),
            'network' => config('siren.blockchain_anchor'),
        ]);
    }

    public function ledgerAnchor(BenefitLedgerService $ledger): RedirectResponse
    {
        $anchor = $ledger->anchor();
        if (! $anchor) {
            return back()->with('status', '새로 묶을 원장 기록이 없어요.');
        }
        Audit::log('ledger.anchored', $anchor, "혜택 원장 앵커 #{$anchor->id} ({$anchor->row_count}건, {$anchor->network})");

        return back()->with('status', "원장 {$anchor->row_count}건을 머클 루트로 묶었어요.");
    }

    private function couponForm(Coupon $coupon): View
    {
        return view('admin.coupon-form', [
            'coupon' => $coupon,
            'stores' => Store::with('vendor')->orderBy('id')->get(),
            'events' => Event::orderByDesc('starts_at')->get(),
            'products' => Product::with('store')->orderBy('store_id')->orderBy('sort')->get(),
        ]);
    }
}
