<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\SettlementAdjustment;
use App\Models\Vendor;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 입점업체 정산 (입점계약 제7조).
 *  - 대상: 기간 안에 수령완료(=구매확정)된 주문. 묶인 주문은 정산완료(6)로 바뀌고 다시 묶이지 않는다.
 *  - 매출 = 판매액(할인 전). 운영사 쿠폰·마일리지 사용분은 운영사가 대신 내므로 매출에 그대로 포함된다.
 *  - 업체 쿠폰 = 업체가 발행한 쿠폰 할인액 (업체 부담), 적립금 = 업체가 상품에 건 마일리지 (업체 부담, 원본 go_money sum(point))
 *  - 수수료 = round((판매액 − 업체 쿠폰) × 주문 시점 매장 수수료율), 부가세 = round(수수료 × 10%)
 *  - 공제 = 차기 정산 공제 항목(정산 후 환불분 등). 지급액이 음수가 되면 0원으로 하고 남은 공제는 다음 정산으로 이월
 *  - 지급액 = 매출 − 업체 쿠폰 − 수수료 − 부가세 − 적립금 − 공제, 지급예정일 = 정산 종료일 + 업체 지급일수
 */
class SettlementService
{
    /**
     * 원샷 판매자 정산 대기목록 (원본 admin/go_money.php mode=seller) — 업체별 수령완료·정산서 없는 주문 합계.
     *  정산 처리([정산완료] = generate(주문일 기준, 업체))로 묶일 주문과 같은 기준으로 센다. 원본에서 옮겨 온 주문은 빠진다.
     *
     * @return Collection<int, array{vendor:Vendor, count:int, gross:int, coupon:int, mileage:int, commission:int, vat:int, payout:int, first:string, last:string}>
     */
    public function pendingByVendor(?string $from = null, ?string $to = null): Collection
    {
        $vatRate = (float) Setting::get('commission_vat_rate');

        return $this->oneshotQuery($from, $to)->where('created_at', '<', PgSettlementService::dueCutoff())   // 정산예정일이 지난 주문만
            ->get()->filter(fn (Order $o) => $o->store?->vendor_id)
            ->groupBy(fn (Order $o) => $o->store->vendor_id)
            ->map(function (Collection $g, $vid) use ($vatRate) {
                $gross = (int) $g->sum('total_amount');
                $coupon = (int) $g->sum(fn (Order $o) => $o->coupon_funded_by === 'vendor' ? $o->coupon_discount : 0);
                $mileage = (int) $g->sum('mileage_earn');
                $commission = (int) $g->sum(fn (Order $o) => $o->commission());
                $vat = (int) round($commission * $vatRate / 100);

                return ['vendor' => Vendor::find($vid), 'count' => $g->count(), 'gross' => $gross, 'coupon' => $coupon, 'mileage' => $mileage,
                    'commission' => $commission, 'vat' => $vat, 'payout' => $gross - $coupon - $commission - $vat - $mileage,
                    'first' => $g->min('created_at')->toDateString(), 'last' => $g->max('created_at')->toDateString()];
            })
            ->filter(fn ($r) => $r['vendor'])->sortBy(fn ($r) => $r['vendor']->name)->values();
    }

    /**
     * 정산예정일이 아직 안 된 수령완료 주문 — 업체별 줄 수·금액·가장 빠른 정산예정일 (원본 sp_notdue_list). 대기목록에서 빠지고 안내만 한다.
     *
     * @return Collection<int, array{vendor:Vendor, count:int, amount:int, first_due:string}>
     */
    public function notDueByVendor(?string $from = null, ?string $to = null): Collection
    {
        return $this->oneshotQuery($from, $to)->where('created_at', '>=', PgSettlementService::dueCutoff())
            ->get()->filter(fn (Order $o) => $o->store?->vendor_id)
            ->groupBy(fn (Order $o) => $o->store->vendor_id)
            ->map(fn (Collection $g, $vid) => ['vendor' => Vendor::find($vid), 'count' => $g->count(), 'amount' => (int) $g->sum('total_amount'),
                'first_due' => PgSettlementService::dueOn($g->min('created_at'))])
            ->filter(fn ($r) => $r['vendor'])->sortBy(fn ($r) => $r['vendor']->name)->values();
    }

    /** 원샷 판매자 정산이 보는 주문: 수령완료·정산서 없음·원본에서 옮겨 온 주문 제외, 주문일 기간 */
    private function oneshotQuery(?string $from, ?string $to): \Illuminate\Database\Eloquent\Builder
    {
        return Order::with('store:id,vendor_id')
            ->where('status', OrderStatus::PickedUp->value)->whereNull('settlement_id')->whereNull('imported_from')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to.' 23:59:59'));
    }

    /**
     * @param  string  $dateField  기간 기준: picked_up_at(수령일, 기본·자동 정산) / created_at(주문일, 원샷 판매자 정산)
     * @param  bool  $dueOnly  정산예정일(주문일 + settle_due_days)이 지난 주문만 (원샷 판매자 정산 — 대기목록에 보인 주문만 정산)
     * @return Collection<int, Settlement> 새로 만든 정산서 (대상 없으면 빈 컬렉션)
     */
    public function generate(CarbonInterface $from, CarbonInterface $to, ?int $vendorId = null, string $dateField = 'picked_up_at', bool $dueOnly = false): Collection
    {
        $dateField = $dateField === 'created_at' ? 'created_at' : 'picked_up_at';
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();
        if ($start->gt($end)) {
            throw new OrderException('정산 시작일이 종료일보다 늦어요.');
        }
        $vatRate = (float) Setting::get('commission_vat_rate');

        $cutoff = $dueOnly ? PgSettlementService::dueCutoff() : null;

        return DB::transaction(function () use ($start, $end, $vendorId, $vatRate, $dateField, $cutoff) {
            $orders = Order::with('store:id,vendor_id')
                ->where('status', OrderStatus::PickedUp->value)
                ->whereNull('settlement_id')
                ->whereNull('imported_from')   // 원본에서 옮겨 온 주문은 원본 정산 기록을 따른다
                ->whereBetween($dateField, [$start, $end])
                ->when($cutoff, fn ($q) => $q->where('created_at', '<', $cutoff))
                ->when($vendorId, fn ($q) => $q->whereHas('store', fn ($s) => $s->where('vendor_id', $vendorId)))
                ->lockForUpdate()
                ->get()
                ->filter(fn (Order $o) => $o->store?->vendor_id);

            return $orders->groupBy(fn (Order $o) => $o->store->vendor_id)->map(function (Collection $group, $vid) use ($start, $end, $vatRate) {
                $vendor = Vendor::findOrFail($vid);
                $gross = (int) $group->sum('total_amount');
                $vendorCoupon = (int) $group->sum(fn (Order $o) => $o->coupon_funded_by === 'vendor' ? $o->coupon_discount : 0);
                $mileageEarn = (int) $group->sum('mileage_earn');
                $commission = (int) $group->sum(fn (Order $o) => $o->commission());   // 업체 쿠폰을 뺀 금액에 수수료
                $vat = (int) round($commission * $vatRate / 100);

                $adjustments = SettlementAdjustment::where('vendor_id', $vid)->whereNull('settlement_id')->lockForUpdate()->get();
                $deduction = (int) $adjustments->sum('amount');
                $payout = $gross - $vendorCoupon - $commission - $vat - $mileageEarn - $deduction;
                $carry = 0;
                if ($payout < 0) {          // 공제가 이번 지급액보다 크면 0원 지급, 나머지는 다음 정산으로
                    $carry = -$payout;
                    $deduction -= $carry;
                    $payout = 0;
                }

                $refunds = Order::whereHas('store', fn ($q) => $q->where('vendor_id', $vid))
                    ->where('status', OrderStatus::Refunded->value)
                    ->whereNull('imported_from')
                    ->whereBetween('refunded_at', [$start, $end]);

                $settlement = Settlement::create([
                    'vendor_id' => $vid,
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'order_count' => $group->count(),
                    'gross_amount' => $gross,
                    'coupon_vendor_amount' => $vendorCoupon,
                    'mileage_earn_amount' => $mileageEarn,
                    'platform_benefit_amount' => (int) $group->sum(fn (Order $o) => $o->platformBenefit()),
                    'commission_amount' => $commission,
                    'commission_vat' => $vat,
                    'deduction_amount' => $deduction,
                    'payout_amount' => $payout,
                    'refund_count' => (clone $refunds)->count(),
                    'refund_amount' => (int) (clone $refunds)->sum('total_amount'),
                    'status' => 'pending',
                    'payout_due_on' => $end->copy()->addDays($vendor->payout_delay_days)->toDateString(),
                ]);

                SettlementAdjustment::whereIn('id', $adjustments->pluck('id'))->update(['settlement_id' => $settlement->id]);
                if ($carry > 0) {
                    SettlementAdjustment::create(['vendor_id' => $vid, 'amount' => $carry, 'reason' => "정산 #{$settlement->id}에서 다 공제하지 못한 금액 이월"]);
                }

                Order::whereIn('id', $group->pluck('id'))->update([
                    'settlement_id' => $settlement->id,
                    'status' => OrderStatus::Settled->value,
                    'settled_at' => now(),
                ]);

                return $settlement;
            })->values();
        });
    }

    /**
     * 자동 정산 (매일 새벽 스케줄러). 오늘이 정산일인 승인 업체만, 직전 정산 다음 날 ~ 어제 분을 정산한다.
     *
     * @return Collection<int, Settlement>
     */
    public function runDue(?CarbonInterface $today = null): Collection
    {
        $today = Carbon::parse($today ?? now())->startOfDay();
        $yesterday = $today->copy()->subDay();
        $made = collect();

        foreach (Vendor::where('state', 'approved')->get() as $vendor) {
            $last = $vendor->settlements()->max('period_end');
            $lastEnd = $last ? Carbon::parse($last) : null;
            if (! $vendor->isSettlementDay($today, $lastEnd)) {
                continue;
            }
            $from = $lastEnd ? $lastEnd->copy()->addDay() : $today->copy()->subDays(62);
            if ($from->gt($yesterday)) {
                continue;
            }
            $made = $made->merge($this->generate($from, $yesterday, $vendor->id));
        }

        return $made;
    }

    public function markPaid(Settlement $settlement): Settlement
    {
        if ($settlement->status === 'paid') {
            throw new OrderException('이미 지급 처리된 정산서예요.', 'already_paid', 409);
        }
        $settlement->update(['status' => 'paid', 'paid_at' => now()]);

        return $settlement;
    }

    /** C9 수수료 세금계산서 발행 표시 (실제 발행은 홈택스/ERP) */
    public function markTaxInvoice(Settlement $settlement): Settlement
    {
        $settlement->update(['tax_invoice_issued_at' => $settlement->tax_invoice_issued_at ? null : now()]);

        return $settlement;
    }

    /** C8 업체 이의제기 — 정산서 발행 후 30일 이내 1회 */
    public function dispute(Settlement $settlement, string $message): Settlement
    {
        if (! $settlement->canDispute()) {
            throw new OrderException('이의제기는 정산서 발행 후 30일 안에 한 번만 할 수 있어요.', 'dispute_closed', 409);
        }
        $settlement->update(['dispute_message' => $message, 'disputed_at' => now()]);

        return $settlement;
    }

    public function replyDispute(Settlement $settlement, string $reply): Settlement
    {
        if (! $settlement->disputed_at) {
            throw new OrderException('이의제기가 없는 정산서예요.');
        }
        $settlement->update(['dispute_reply' => $reply, 'dispute_replied_at' => now()]);

        return $settlement;
    }
}
