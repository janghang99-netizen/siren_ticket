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
 *  - 수수료 = round(주문금액 × 주문 시점 매장 수수료율), 부가세 = round(수수료 × 10%) — 수수료 부가세 별도
 *  - 공제 = 차기 정산 공제 항목(정산 후 환불분 등). 지급액이 음수가 되면 0원으로 하고 남은 공제는 다음 정산으로 이월
 *  - 지급액 = 매출 − 수수료 − 부가세 − 공제, 지급예정일 = 정산 종료일 + 업체 지급일수
 */
class SettlementService
{
    /** @return Collection<int, Settlement> 새로 만든 정산서 (대상 없으면 빈 컬렉션) */
    public function generate(CarbonInterface $from, CarbonInterface $to, ?int $vendorId = null): Collection
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();
        if ($start->gt($end)) {
            throw new OrderException('정산 시작일이 종료일보다 늦어요.');
        }
        $vatRate = (float) Setting::get('commission_vat_rate');

        return DB::transaction(function () use ($start, $end, $vendorId, $vatRate) {
            $orders = Order::with('store:id,vendor_id')
                ->where('status', OrderStatus::PickedUp->value)
                ->whereNull('settlement_id')
                ->whereBetween('picked_up_at', [$start, $end])
                ->when($vendorId, fn ($q) => $q->whereHas('store', fn ($s) => $s->where('vendor_id', $vendorId)))
                ->lockForUpdate()
                ->get()
                ->filter(fn (Order $o) => $o->store?->vendor_id);

            return $orders->groupBy(fn (Order $o) => $o->store->vendor_id)->map(function (Collection $group, $vid) use ($start, $end, $vatRate) {
                $vendor = Vendor::findOrFail($vid);
                $gross = (int) $group->sum('total_amount');
                $commission = (int) $group->sum(fn (Order $o) => (int) round($o->total_amount * (float) $o->commission_rate / 100));
                $vat = (int) round($commission * $vatRate / 100);

                $adjustments = SettlementAdjustment::where('vendor_id', $vid)->whereNull('settlement_id')->lockForUpdate()->get();
                $deduction = (int) $adjustments->sum('amount');
                $payout = $gross - $commission - $vat - $deduction;
                $carry = 0;
                if ($payout < 0) {          // 공제가 이번 지급액보다 크면 0원 지급, 나머지는 다음 정산으로
                    $carry = -$payout;
                    $deduction -= $carry;
                    $payout = 0;
                }

                $refunds = Order::whereHas('store', fn ($q) => $q->where('vendor_id', $vid))
                    ->where('status', OrderStatus::Refunded->value)
                    ->whereBetween('refunded_at', [$start, $end]);

                $settlement = Settlement::create([
                    'vendor_id' => $vid,
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'order_count' => $group->count(),
                    'gross_amount' => $gross,
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
