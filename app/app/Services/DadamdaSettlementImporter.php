<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Settlement;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 원본 다담다 정산 기록 → 새 정산서. 상품·판매 이관 다음에 한다.
 *
 * - 원본 정산 1회 = 새 정산서 1장 (지급 완료). 그 정산 시각 전에 산 '정산완료' 이관 주문을 그 정산서에 묶는다.
 * - 원본 금액(매출·상품 줄 수·마일리지·정산금)과 옮긴 주문으로 다시 계산한 금액이 1원이라도 다르면 넣지 않는다.
 * - 원본 수수료는 부가세 포함 금액이라 수수료와 부가세(10%)로 나눠 넣는다.
 * - 원본 '정산 대기'는 비교만 하고 정산서를 만들지 않는다 (어디서 정산할지는 운영사가 정한다).
 * - 계좌번호는 다루지 않는다 (운영사 화면에서 입력).
 */
class DadamdaSettlementImporter
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{created:int, skipped_existing:int, linked_orders:int, vendor_filled:array<int,string>, pending:?array{expected:int, actual:int, orders:int}, errors:array<int,string>}
     */
    public function import(array $data, Store $store): array
    {
        $report = ['created' => 0, 'skipped_existing' => 0, 'linked_orders' => 0, 'vendor_filled' => [], 'pending' => null, 'errors' => []];
        $seller = (string) ($data['seller'] ?? '');
        $vendor = $store->vendor;
        if (! $vendor) {
            $report['errors'][] = '매장에 연결된 업체가 없어요.';

            return $report;
        }

        // 세금계산서용 사업자 정보 — 비어 있을 때만 채운다 (운영사가 고친 값은 덮어쓰지 않음)
        foreach (['business_no', 'representative'] as $field) {
            $value = trim((string) ($data['vendor'][$field] ?? ''));
            if ($value !== '' && blank($vendor->{$field})) {
                $vendor->{$field} = $value;
                $report['vendor_filled'][] = $field;
            }
        }
        $vendor->save();

        $settlements = collect($data['settlements'] ?? [])->sortBy('at')->values();
        foreach ($settlements as $s) {
            $at = Carbon::parse((string) $s['at'], 'Asia/Seoul');
            $ref = "dadamda:{$seller}:{$at->format('Y-m-d H:i:s')}";
            if (Settlement::where('legacy_ref', $ref)->exists()) {
                $report['skipped_existing']++;

                continue;
            }

            $orders = Order::where('store_id', $store->id)
                ->where('imported_from', DadamdaOrderImporter::SOURCE)
                ->where('status', OrderStatus::Settled->value)
                ->whereNull('settlement_id')
                ->where('paid_at', '<', $at)
                ->get(['id', 'total_amount', 'mileage_used', 'paid_at']);

            $gross = (int) $orders->sum('total_amount');
            $mileage = (int) $orders->sum('mileage_used');
            $items = OrderItem::whereIn('order_id', $orders->pluck('id'))->count();
            $fee = (int) $s['fee'];
            $net = (int) $s['net'];
            $deduction = (int) ($s['prepaid_deduction'] ?? 0);

            $checks = [
                '매출' => [(int) $s['gross'], $gross],
                '상품 줄 수' => [(int) $s['items'], $items],
                '마일리지 사용' => [(int) ($s['mileage_used'] ?? 0), $mileage],
                '정산금(매출-수수료-선차감)' => [$net, $gross - $fee - $deduction],
            ];
            $diff = collect($checks)->filter(fn ($pair) => $pair[0] !== $pair[1]);
            if ($diff->isNotEmpty()) {
                $report['errors'][] = "{$s['at']} 정산: 원본과 옮긴 주문이 달라 넣지 않았어요 — "
                    .$diff->map(fn ($p, $k) => "{$k} 원본 ".number_format($p[0]).' / 계산 '.number_format($p[1]))->implode(', ');

                continue;
            }

            DB::transaction(function () use ($vendor, $ref, $at, $orders, $gross, $mileage, $fee, $net, $deduction, &$report) {
                $commission = (int) round($fee / 1.1);   // 원본 수수료 = 수수료 + 부가세 10%
                $settlement = Settlement::create([
                    'vendor_id' => $vendor->id,
                    'legacy_ref' => $ref,
                    'period_start' => $orders->min('paid_at')->timezone('Asia/Seoul')->toDateString(),
                    'period_end' => $at->copy()->subDay()->toDateString(),
                    'order_count' => $orders->count(),
                    'gross_amount' => $gross,
                    'platform_benefit_amount' => $mileage,
                    'commission_amount' => $commission,
                    'commission_vat' => $fee - $commission,
                    'deduction_amount' => $deduction,
                    'payout_amount' => $net,
                    'status' => 'paid',
                    'paid_at' => $at,
                    'payout_due_on' => $at->toDateString(),
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
                Order::whereIn('id', $orders->pluck('id'))->update(['settlement_id' => $settlement->id, 'settled_at' => $at]);
                $report['created']++;
                $report['linked_orders'] += $orders->count();
            });
        }

        // 원본 정산 대기 ↔ 옮긴 '수령완료' 주문 비교 (정산서는 만들지 않음)
        if (isset($data['pending']['gross'])) {
            $picked = Order::where('store_id', $store->id)->where('imported_from', DadamdaOrderImporter::SOURCE)
                ->where('status', OrderStatus::PickedUp->value)->whereNull('settlement_id');
            $report['pending'] = ['expected' => (int) $data['pending']['gross'], 'actual' => (int) $picked->sum('total_amount'), 'orders' => $picked->count()];
        }

        return $report;
    }
}
