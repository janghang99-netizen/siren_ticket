<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Settlement;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 입점업체 정산.
 * 기간 안에 수령완료(5)된 주문을 업체별로 묶어 정산서를 만들고, 주문은 정산완료(6)로 바꾼다.
 * 주문당 수수료 = round(주문금액 × 주문 시점 수수료율). 이미 정산서에 들어간 주문은 다시 안 들어간다.
 */
class SettlementService
{
    /** @return Collection<int, Settlement> 새로 만든 정산서 (대상 없으면 빈 컬렉션) */
    public function generate(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();
        if ($start->gt($end)) {
            throw new OrderException('정산 시작일이 종료일보다 늦어요.');
        }

        return DB::transaction(function () use ($start, $end) {
            $orders = Order::with('store:id,vendor_id')
                ->where('status', OrderStatus::PickedUp->value)
                ->whereNull('settlement_id')
                ->whereBetween('picked_up_at', [$start, $end])
                ->lockForUpdate()
                ->get()
                ->filter(fn (Order $o) => $o->store?->vendor_id);

            return $orders->groupBy(fn (Order $o) => $o->store->vendor_id)->map(function (Collection $group, $vendorId) use ($start, $end) {
                $gross = (int) $group->sum('total_amount');
                $commission = (int) $group->sum(fn (Order $o) => (int) round($o->total_amount * (float) $o->commission_rate / 100));

                $refunds = Order::whereHas('store', fn ($q) => $q->where('vendor_id', $vendorId))
                    ->where('status', OrderStatus::Refunded->value)
                    ->whereBetween('refunded_at', [$start, $end]);

                $settlement = Settlement::create([
                    'vendor_id' => $vendorId,
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'order_count' => $group->count(),
                    'gross_amount' => $gross,
                    'commission_amount' => $commission,
                    'payout_amount' => $gross - $commission,
                    'refund_count' => (clone $refunds)->count(),
                    'refund_amount' => (int) (clone $refunds)->sum('total_amount'),
                    'status' => 'pending',
                ]);

                Order::whereIn('id', $group->pluck('id'))->update([
                    'settlement_id' => $settlement->id,
                    'status' => OrderStatus::Settled->value,
                    'settled_at' => now(),
                ]);

                return $settlement;
            })->values();
        });
    }

    public function markPaid(Settlement $settlement): Settlement
    {
        if ($settlement->status === 'paid') {
            throw new OrderException('이미 지급 처리된 정산서예요.', 'already_paid', 409);
        }
        $settlement->update(['status' => 'paid', 'paid_at' => now()]);

        return $settlement;
    }
}
