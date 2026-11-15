<?php

namespace App\Services\Benefits;

use App\Models\BenefitLedger;
use App\Models\Coupon;
use App\Models\CouponIssue;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Order;
use App\Models\Setting;
use App\Models\StampCard;
use App\Models\StampProgram;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\OrderException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 마일리지 · 쿠폰 · 스탬프 (휴대폰 번호 = 고객).
 *
 * 원본 다담다와 다른 점 (조사에서 나온 결함 수정):
 *  - 마일리지 차감은 "잔액 ≥ 사용액" 조건부 UPDATE 하나 → 동시 결제로 잔액이 음수가 되지 않는다.
 *  - 적립·사용·반환·회수는 idem_key 로 한 번만 → 콜백 중복·재클릭에도 이중 처리 없음.
 *  - 수령 후 환불이면 적립금도 회수한다 (원본은 회수하지 않았다).
 *  - 사용 한도·최소 사용액은 서버에서 검사한다 (원본은 화면에서만).
 *  - 스탬프 보상 쿠폰 발급은 카드 행을 잠근 채 한 트랜잭션에서 → 이중 발급 없음.
 *
 * 비용 부담: 업체 쿠폰·주문 적립 마일리지 = 업체(정산에서 뺌), 운영사 쿠폰·마일리지 사용·티켓 적립 = 운영사.
 */
class BenefitService
{
    public function __construct(private BenefitLedgerService $ledger) {}

    // ───────────────────────── 견적 (주문·예매 전 확인) ─────────────────────────

    /**
     * 쿠폰·마일리지를 검사해 할인액을 정한다. 저장은 하지 않는다.
     *
     * @param  array<int, int>  $unitPrices  product_id => 단가 (무료 상품 쿠폰용)
     * @return array{issue:?CouponIssue, coupon:int, funded_by:?string, mileage:int, pay:int}
     */
    public function quote(Customer $customer, string $scope, int $amount, ?Store $store, ?Event $event,
        ?int $couponIssueId, int $mileage, array $unitPrices = []): array
    {
        $issue = null;
        $discount = 0;
        if ($couponIssueId) {
            $issue = CouponIssue::with('coupon')->where('customer_id', $customer->id)->find($couponIssueId)
                ?? throw new OrderException('내 쿠폰이 아니에요.', 'coupon_invalid');
            $c = $issue->coupon;
            if (! $issue->usable()) {
                throw new OrderException('이미 썼거나 기간이 지난 쿠폰이에요.', 'coupon_invalid');
            }
            if ($c->scope !== $scope || ! $c->appliesTo($store, $event)) {
                throw new OrderException('여기서는 쓸 수 없는 쿠폰이에요.', 'coupon_invalid');
            }
            if ($amount < $c->min_amount) {
                throw new OrderException(number_format($c->min_amount).'원 이상 살 때 쓸 수 있는 쿠폰이에요.', 'coupon_invalid');
            }
            $discount = $c->discountFor($amount, $unitPrices);
            if ($discount <= 0) {
                throw new OrderException($c->kind === 'free_item' ? '무료 쿠폰 상품을 먼저 담아 주세요.' : '할인할 금액이 없어요.', 'coupon_invalid');
            }
        }

        $mileage = max(0, $mileage);
        if ($mileage > 0) {
            $min = (int) Setting::get('mileage_min_use');
            $cap = (int) floor(($amount - $discount) * (int) Setting::get('mileage_use_max_rate') / 100);
            if ($mileage < $min) {
                throw new OrderException('마일리지는 '.number_format($min).'원부터 쓸 수 있어요.', 'mileage_invalid');
            }
            if ($mileage > $customer->mileage_balance) {
                throw new OrderException('마일리지가 모자라요. 지금 '.number_format($customer->mileage_balance).'원 있어요.', 'mileage_invalid');
            }
            if ($mileage > $cap) {
                throw new OrderException('이번 결제에는 마일리지를 '.number_format($cap).'원까지 쓸 수 있어요.', 'mileage_invalid');
            }
        }

        return [
            'issue' => $issue, 'coupon' => $discount, 'funded_by' => $issue?->coupon->issuer,
            'mileage' => $mileage, 'pay' => $amount - $discount - $mileage,
        ];
    }

    // ───────────────────────── 매장 주문 ─────────────────────────

    /** OrderService::place 트랜잭션 안에서: 쿠폰을 쓰고 마일리지를 뺀 뒤 주문에 기록한다. */
    public function applyToOrder(Order $order, Customer $customer, Store $store, ?int $couponIssueId, int $mileage, array $unitPrices): void
    {
        $q = $this->quote($customer, 'order', $order->total_amount, $store, null, $couponIssueId, $mileage, $unitPrices);
        $subject = 'order:'.$order->order_no;
        if ($q['issue']) {
            $this->useCoupon($q['issue'], $customer, 'order', $order->order_no, $subject);
        }
        if ($q['mileage'] > 0) {
            $this->spendMileage($customer, $q['mileage'], $subject, "mileage:use:{$subject}");
        }
        $order->update([
            'coupon_issue_id' => $q['issue']?->id, 'coupon_discount' => $q['coupon'], 'coupon_funded_by' => $q['funded_by'],
            'mileage_used' => $q['mileage'], 'paid_amount' => $q['pay'],
        ]);
    }

    /** 결제 전 취소·만료·환불: 쓴 쿠폰과 마일리지를 돌려준다 (주문 잠금 트랜잭션 안에서). */
    public function releaseOrder(Order $order, bool $restoreCoupon = true): void
    {
        if (! $order->customer_id) {
            return;
        }
        $customer = Customer::find($order->customer_id);
        $subject = 'order:'.$order->order_no;
        if ($order->mileage_used > 0) {
            $this->refundMileage($customer, $order->mileage_used, $subject, "mileage:restore:{$subject}");
        }
        if ($restoreCoupon && $order->coupon_issue_id) {
            $this->restoreCoupon($order->coupon_issue_id, $customer, $order->order_no, $subject);
        }
    }

    /** 수령완료: 업체가 정한 적립금 지급 + 매장 스탬프 */
    public function earnForOrder(Order $order): void
    {
        if (! $order->customer_id) {
            return;
        }
        $customer = Customer::find($order->customer_id);
        $subject = 'order:'.$order->order_no;
        if ($order->mileage_earn > 0) {
            $this->addMileage($customer, $order->mileage_earn, 'earn', $subject, '매장 주문 적립', "mileage:earn:{$subject}");
        }

        $program = StampProgram::where('owner', 'store')->where('store_id', $order->store_id)->where('is_active', true)->first();
        if ($program && $order->total_amount >= $program->min_amount && $order->paid_amount + $order->mileage_used > 0) {
            $qty = (int) $order->items()->sum('qty');
            if ($order->coupon_issue_id && CouponIssue::find($order->coupon_issue_id)?->coupon?->kind === 'free_item') {
                $qty--;   // 무료로 받은 1개는 스탬프에서 뺀다 (원본: 0원 주문 제외)
            }
            if ($qty > 0) {
                $this->stamp($program, $customer, $qty, $subject, "stamp:earn:{$subject}");
            }
        }
    }

    /** 수령 후 운영사 환불: 쓴 마일리지는 돌려주고, 받은 적립금·스탬프는 회수한다. 쿠폰은 돌려주지 않는다. */
    public function revokeForOrder(Order $order): void
    {
        if (! $order->customer_id) {
            return;
        }
        $this->releaseOrder($order, restoreCoupon: false);
        $customer = Customer::find($order->customer_id);
        $subject = 'order:'.$order->order_no;
        if ($this->ledger->done("mileage:earn:{$subject}")) {
            $this->takeBackMileage($customer, $order->mileage_earn, $subject, "mileage:revoke:{$subject}");
        }
        $earned = BenefitLedger::where('idem_key', "stamp:earn:{$subject}")->first();
        if ($earned) {
            $this->unstamp($earned->ref_id, $customer, $earned->amount, $subject, "stamp:revoke:{$subject}");
        }
    }

    // ───────────────────────── 공연 티켓 ─────────────────────────

    /**
     * TicketService::purchase 트랜잭션 안에서: 예매 전체에 쿠폰·마일리지를 적용하고 티켓별로 나눠 적는다
     * (원본 use_point_divide 와 같은 방식 — 부분 취소 때 그 티켓 몫만 돌려주기 위해).
     *
     * @param  Collection<int, Ticket>  $tickets
     */
    public function applyToBooking(Collection $tickets, Customer $customer, Event $event, string $bookingNo, ?int $couponIssueId, int $mileage): void
    {
        $total = (int) $tickets->sum('price');
        $q = $this->quote($customer, 'ticket', $total, null, $event, $couponIssueId, $mileage);
        $subject = 'ticket:'.$bookingNo;
        if ($q['issue']) {
            $this->useCoupon($q['issue'], $customer, 'ticket', $bookingNo, $subject);
        }
        if ($q['mileage'] > 0) {
            $this->spendMileage($customer, $q['mileage'], $subject, "mileage:use:{$subject}");
        }

        $couponLeft = $q['coupon'];
        $mileageLeft = $q['mileage'];
        $last = $tickets->count() - 1;
        foreach ($tickets->values() as $i => $t) {
            $c = $i === $last ? $couponLeft : (int) floor($q['coupon'] * $t->price / max(1, $total));
            $m = $i === $last ? $mileageLeft : min((int) floor($q['mileage'] * $t->price / max(1, $total)), $t->price - $c);
            $couponLeft -= $c;
            $mileageLeft -= $m;
            $t->update(['coupon_issue_id' => $q['issue']?->id, 'coupon_discount' => $c, 'mileage_used' => $m, 'paid_amount' => $t->price - $c - $m]);
        }
    }

    /**
     * 티켓 취소: 취소 수수료는 정가 기준. 현금 결제분에서 먼저 빼고, 모자라면 마일리지 몫에서 뺀다.
     * 예매의 티켓이 모두 취소되면 쿠폰을 돌려준다.
     *
     * @return int 현금 환불액
     */
    public function releaseTicket(Ticket $ticket, int $fee): int
    {
        $cash = max(0, $ticket->paid_amount - $fee);
        if (! $ticket->customer_id) {
            return $cash;
        }
        $customer = Customer::find($ticket->customer_id);
        $mileageBack = max(0, $ticket->mileage_used - max(0, $fee - $ticket->paid_amount));
        if ($mileageBack > 0) {
            $this->refundMileage($customer, $mileageBack, 'ticket:'.$ticket->booking_no, "mileage:restore:ticket:{$ticket->ticket_no}");
        }
        if ($ticket->coupon_issue_id && $ticket->booking_no
            && ! Ticket::where('booking_no', $ticket->booking_no)->where('id', '!=', $ticket->id)->where('status', '!=', 'cancelled')->exists()) {
            $this->restoreCoupon($ticket->coupon_issue_id, $customer, $ticket->booking_no, 'ticket:'.$ticket->booking_no);
        }

        return $cash;
    }

    /** 관람이 끝난 티켓에 마일리지(운영사 부담, 결제액의 %)와 관람 스탬프를 준다. 스케줄러가 부른다. */
    public function grantAttendance(): int
    {
        $rate = (float) Setting::get('ticket_mileage_rate');
        $program = StampProgram::where('owner', 'platform')->where('is_active', true)->first();
        $count = 0;
        $ids = Ticket::whereNull('benefit_granted_at')->whereNotNull('customer_id')->whereIn('status', ['paid', 'used'])
            ->whereHas('event', fn ($q) => $q->where('status', 'scheduled')->where('starts_at', '<=', now()->subHours(3)))
            ->pluck('id');

        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id, $rate, $program) {
                $t = Ticket::whereKey($id)->lockForUpdate()->first();
                if (! $t || $t->benefit_granted_at || $t->status === 'cancelled') {
                    return 0;
                }
                $customer = Customer::find($t->customer_id);
                $subject = 'ticket:'.$t->ticket_no;
                $earn = (int) floor($t->paid_amount * $rate / 100);
                if ($earn > 0) {
                    $this->addMileage($customer, $earn, 'earn', $subject, '공연 관람 적립', "mileage:earn:{$subject}");
                }
                if ($program) {
                    $this->stamp($program, $customer, 1, $subject, "stamp:earn:{$subject}");
                }
                $t->update(['benefit_granted_at' => now()]);

                return 1;
            });
        }

        return $count;
    }

    // ───────────────────────── 쿠폰 받기 ─────────────────────────

    /** 공개 쿠폰 받기 / 코드 입력. 1인 1매는 (coupon, customer, seq=1) 유니크 키가 지킨다. */
    public function claim(Coupon $coupon, Customer $customer): CouponIssue
    {
        if (CouponIssue::where('coupon_id', $coupon->id)->where('customer_id', $customer->id)->exists()) {
            throw new OrderException('이미 받은 쿠폰이에요.', 'coupon_duplicate');
        }
        if ($coupon->source !== 'manual' || ! $coupon->claimable()) {
            throw new OrderException($coupon->total_limit !== null && $coupon->issued_count >= $coupon->total_limit
                ? '쿠폰이 모두 소진됐어요.' : '지금 받을 수 없는 쿠폰이에요.', 'coupon_closed');
        }

        try {
            return DB::transaction(function () use ($coupon, $customer) {
                $got = Coupon::whereKey($coupon->id)
                    ->where(fn ($q) => $q->whereNull('total_limit')->orWhereColumn('issued_count', '<', 'total_limit'))
                    ->increment('issued_count');
                if ($got === 0) {
                    throw new OrderException('쿠폰이 모두 소진됐어요.', 'coupon_closed');
                }
                $issue = CouponIssue::create([
                    'coupon_id' => $coupon->id, 'customer_id' => $customer->id, 'seq' => 1, 'status' => 'issued',
                    'expires_at' => $this->expiryFor($coupon),
                ]);
                $this->ledger->append($customer, 'coupon', 'issue', 1, null, $issue->id, null, $coupon->name, null, "coupon:issue:{$issue->id}");

                return $issue;
            });
        } catch (QueryException $e) {   // 동시에 두 번 눌러도 유니크 키가 두 번째를 막는다
            throw new OrderException('이미 받은 쿠폰이에요.', 'coupon_duplicate');
        }
    }

    public function claimByCode(string $code, Customer $customer): CouponIssue
    {
        $coupon = Coupon::where('code', strtoupper(trim($code)))->first()
            ?? throw new OrderException('없는 쿠폰 코드예요.', 'coupon_invalid');

        return $this->claim($coupon, $customer);
    }

    /** 운영사·업체가 특정 고객에게 직접 지급 (CS 보상, 스탬프 보상). 같은 쿠폰을 여러 장 줄 수 있다. */
    public function grant(Coupon $coupon, Customer $customer, ?string $memo = null, ?int $userId = null): CouponIssue
    {
        return DB::transaction(function () use ($coupon, $customer, $memo, $userId) {
            Coupon::whereKey($coupon->id)->lockForUpdate()->first();
            $seq = (int) CouponIssue::where('coupon_id', $coupon->id)->where('customer_id', $customer->id)->max('seq') + 1;
            $issue = CouponIssue::create([
                'coupon_id' => $coupon->id, 'customer_id' => $customer->id, 'seq' => $seq, 'status' => 'issued',
                'expires_at' => $this->expiryFor($coupon),
            ]);
            Coupon::whereKey($coupon->id)->increment('issued_count');
            $this->ledger->append($customer, 'coupon', 'issue', 1, null, $issue->id, null, $memo ?? $coupon->name, $userId, "coupon:issue:{$issue->id}");

            return $issue;
        });
    }

    /** @return Collection<int, CouponIssue> 여기서 쓸 수 있는 내 쿠폰 */
    public function usableCoupons(Customer $customer, string $scope, ?Store $store, ?Event $event): Collection
    {
        return CouponIssue::with('coupon.product')->where('customer_id', $customer->id)->where('status', 'issued')->get()
            ->filter(fn (CouponIssue $i) => $i->usable() && $i->coupon->scope === $scope && $i->coupon->appliesTo($store, $event))
            ->values();
    }

    // ───────────────────────── 마일리지 수동 조정 ─────────────────────────

    public function adjustMileage(Customer $customer, int $amount, string $memo, int $userId): void
    {
        DB::transaction(function () use ($customer, $amount, $memo, $userId) {
            if ($amount >= 0) {
                $this->addMileage($customer, $amount, 'adjust', null, $memo, null, $userId);

                return;
            }
            $ok = Customer::whereKey($customer->id)->where('mileage_balance', '>=', -$amount)->decrement('mileage_balance', -$amount);
            if (! $ok) {
                throw new OrderException('잔액보다 많이 뺄 수 없어요.', 'mileage_invalid');
            }
            $this->ledger->append($customer, 'mileage', 'adjust', $amount, $customer->fresh()->mileage_balance, null, null, $memo, $userId);
        });
    }

    // ───────────────────────── 내부 ─────────────────────────

    private function useCoupon(CouponIssue $issue, Customer $customer, string $for, string $ref, string $subject): void
    {
        $ok = CouponIssue::whereKey($issue->id)->where('status', 'issued')
            ->update(['status' => 'used', 'used_at' => now(), 'used_for' => $for, 'used_ref' => $ref]);
        if (! $ok) {
            throw new OrderException('이미 쓴 쿠폰이에요.', 'coupon_invalid');
        }
        Coupon::whereKey($issue->coupon_id)->increment('used_count');
        $this->ledger->append($customer, 'coupon', 'use', -1, null, $issue->id, $subject, $issue->coupon->name, null, "coupon:use:{$subject}");
    }

    private function restoreCoupon(int $issueId, Customer $customer, string $ref, string $subject): void
    {
        if ($this->ledger->done("coupon:restore:{$subject}")) {
            return;
        }
        $ok = CouponIssue::whereKey($issueId)->where('status', 'used')->where('used_ref', $ref)
            ->update(['status' => 'issued', 'used_at' => null, 'used_for' => null, 'used_ref' => null]);
        if ($ok) {
            $couponId = CouponIssue::whereKey($issueId)->value('coupon_id');
            Coupon::whereKey($couponId)->where('used_count', '>', 0)->decrement('used_count');
            $this->ledger->append($customer, 'coupon', 'restore', 1, null, $issueId, $subject, '취소로 쿠폰 반환', null, "coupon:restore:{$subject}");
        }
    }

    private function spendMileage(Customer $customer, int $amount, string $subject, string $idem): void
    {
        $ok = Customer::whereKey($customer->id)->where('mileage_balance', '>=', $amount)->decrement('mileage_balance', $amount);
        if (! $ok) {
            throw new OrderException('마일리지가 모자라요.', 'mileage_invalid');
        }
        $this->ledger->append($customer, 'mileage', 'use', -$amount, $customer->fresh()->mileage_balance, null, $subject, null, null, $idem);
    }

    private function refundMileage(Customer $customer, int $amount, string $subject, string $idem): void
    {
        if ($this->ledger->done($idem)) {
            return;
        }
        Customer::whereKey($customer->id)->increment('mileage_balance', $amount);
        $this->ledger->append($customer, 'mileage', 'restore', $amount, $customer->fresh()->mileage_balance, null, $subject, '취소로 사용 마일리지 반환', null, $idem);
    }

    private function addMileage(Customer $customer, int $amount, string $type, ?string $subject, ?string $memo, ?string $idem, ?int $userId = null): void
    {
        if ($idem && $this->ledger->done($idem)) {
            return;
        }
        Customer::whereKey($customer->id)->increment('mileage_balance', $amount);
        $this->ledger->append($customer, 'mileage', $type, $amount, $customer->fresh()->mileage_balance, null, $subject, $memo, $userId, $idem);
    }

    /** 적립금 회수: 이미 써 버려 잔액이 모자라면 남은 만큼만 회수하고 메모에 남긴다 (잔액은 음수로 만들지 않는다). */
    private function takeBackMileage(Customer $customer, int $amount, string $subject, string $idem): void
    {
        if ($this->ledger->done($idem)) {
            return;
        }
        $c = Customer::whereKey($customer->id)->lockForUpdate()->first();
        $take = min($amount, $c->mileage_balance);
        if ($take > 0) {
            Customer::whereKey($c->id)->decrement('mileage_balance', $take);
        }
        $memo = $take < $amount ? '환불로 적립금 회수 (잔액 부족 '.number_format($amount - $take).'원 미회수)' : '환불로 적립금 회수';
        $this->ledger->append($c, 'mileage', 'revoke', -$take, $c->mileage_balance - $take, null, $subject, $memo, null, $idem);
    }

    private function stamp(StampProgram $program, Customer $customer, int $qty, string $subject, string $idem): void
    {
        if ($this->ledger->done($idem)) {
            return;
        }
        $card = StampCard::firstOrCreate(['stamp_program_id' => $program->id, 'customer_id' => $customer->id]);
        $card = StampCard::whereKey($card->id)->lockForUpdate()->first();
        $count = $card->count + $qty;
        $this->ledger->append($customer, 'stamp', 'earn', $qty, $count, $program->id, $subject, $program->name, null, $idem);

        $reward = $program->rewardCoupon;
        while ($reward && $program->need_count > 0 && $count >= $program->need_count) {
            $count -= $program->need_count;
            $this->ledger->append($customer, 'stamp', 'redeem', -$program->need_count, $count, $program->id, $subject, $reward->name.' 받음');
            $this->grant($reward, $customer, $program->name.' 스탬프 보상');
        }
        $card->update(['count' => $count, 'total_earned' => $card->total_earned + $qty]);
    }

    private function unstamp(int $programId, Customer $customer, int $qty, string $subject, string $idem): void
    {
        if ($this->ledger->done($idem)) {
            return;
        }
        $card = StampCard::where('stamp_program_id', $programId)->where('customer_id', $customer->id)->lockForUpdate()->first();
        if (! $card) {
            return;
        }
        // 이미 보상 쿠폰으로 바꿨다면 음수가 될 수 있다 → 다음에 찍히는 스탬프로 메운다
        $card->update(['count' => $card->count - $qty]);
        $this->ledger->append($customer, 'stamp', 'revoke', -$qty, $card->count, $programId, $subject, '환불로 스탬프 회수', null, $idem);
    }

    private function expiryFor(Coupon $coupon): ?Carbon
    {
        $byDays = $coupon->valid_days ? now()->addDays($coupon->valid_days)->endOfDay() : null;
        $end = $coupon->ends_at ? Carbon::parse($coupon->ends_at) : null;

        if ($byDays && $end) {
            return $byDays->lt($end) ? $byDays : $end;
        }

        return $byDays ?? $end;
    }
}
