<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 원본 다담다 판매(주문) 기록 → 새 주문 구조로 옮기기. 상품 이관(DadamdaProductImporter)을 먼저 한다.
 *
 * - 구매자 아이디·이름·전화번호는 가져오지 않는다 (POS 주문인지만 남긴다)
 * - 입금대기(결제 안 됨)는 판매가 아니라서 가져오지 않는다
 * - 옮긴 주문은 imported_from='dadamda' 표시 → 새 자동 정산에 들어가지 않는다 (원본 정산 기록을 따른다)
 * - 재고를 다시 빼지 않고 문자·알림도 보내지 않는다 (지난 기록일 뿐)
 * - 같은 주문번호가 이미 있으면 건너뛴다 (다시 돌려도 안전)
 */
class DadamdaOrderImporter
{
    public const SOURCE = 'dadamda';

    /** 원본 상태 → 새 상태 (null = 가져오지 않음) */
    private const STATUS = [
        '정산완료' => OrderStatus::Settled,
        '수령완료' => OrderStatus::PickedUp,
        '환불완료' => OrderStatus::Refunded,
        '판매취소' => OrderStatus::Cancelled,
        '판매취소완료' => OrderStatus::Cancelled,
        '구매취소요청' => OrderStatus::Cancelled,   // 진행 중인 주문처럼 보이지 않게 — 보고서에 따로 적는다
        '입금대기' => null,
    ];

    private const PAY = [
        '현장판매/현장카드' => 'pos_card',
        '현장판매/현금' => 'pos_cash',
        '현장판매/계좌이체' => 'pos_transfer',
        '카드결제' => 'card',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $orders
     * @return array{created:int, skipped_existing:int, skipped_unpaid:int, placeholders:array<int,string>, cancel_requests:array<int,string>, errors:array<int,string>, total:int}
     */
    public function import(array $orders, Store $store): array
    {
        $report = ['created' => 0, 'skipped_existing' => 0, 'skipped_unpaid' => 0, 'placeholders' => [], 'cancel_requests' => [], 'errors' => [], 'total' => 0];

        foreach ($orders as $o) {
            $no = (string) ($o['no'] ?? '');
            $statusText = (string) ($o['status'] ?? '');
            if ($no === '' || empty($o['lines']) || empty($o['at'])) {
                $report['errors'][] = "주문 {$no}: 번호·시각·상품이 없어 건너뜀";

                continue;
            }
            if (! array_key_exists($statusText, self::STATUS)) {
                $report['errors'][] = "주문 {$no}: 알 수 없는 상태 '{$statusText}' — 건너뜀";

                continue;
            }
            $status = self::STATUS[$statusText];
            if ($status === null) {
                $report['skipped_unpaid']++;

                continue;
            }
            if (Order::where('order_no', $no)->exists()) {
                $report['skipped_existing']++;

                continue;
            }

            DB::transaction(function () use ($o, $no, $store, $status, $statusText, &$report) {
                $at = Carbon::parse($o['at'], 'Asia/Seoul');
                $lines = [];
                foreach ($o['lines'] as [$productNo, $title, $optionText, $dine, $qty, $total]) {
                    $qty = max(1, (int) $qty);
                    $total = max(0, (int) $total);
                    $product = $this->product($store, (int) $productNo, (string) $title, intdiv($total, $qty), $report);
                    $option = $optionText ? $product->options()->where('name', $optionText)->first() : null;
                    $lines[] = [
                        'product_id' => $product->id,
                        'product_option_id' => $option?->id,
                        'name' => mb_substr($this->baseName((string) $title), 0, 255),
                        'option_name' => $optionText ? mb_substr((string) $optionText, 0, 255) : null,
                        'unit_price' => intdiv($total, $qty),
                        'qty' => $qty,
                        'line_total' => $total,
                        'dine_option' => $dine === '매장' ? '매장' : '포장',
                        'stock_deducted' => false,
                    ];
                }
                $sum = array_sum(array_column($lines, 'line_total'));
                $mileage = min($sum, max(0, (int) ($o['mileage'] ?? 0)));

                $order = new Order([
                    'order_no' => $no,
                    'pickup_code' => str_pad(substr((string) preg_replace('/\D/', '', $no), -4), 4, '0', STR_PAD_LEFT),
                    'store_id' => $store->id,
                    'status' => $status,
                    'channel' => ! empty($o['pos']) ? 'pos' : 'online',
                    'imported_from' => self::SOURCE,
                    'pay_method' => mb_substr(self::PAY[$o['pay'] ?? ''] ?? (($o['pay'] ?? '') ?: '알수없음'), 0, 30),
                    'total_amount' => $sum,
                    'mileage_used' => $mileage,
                    'paid_amount' => $sum - $mileage,
                    'commission_rate' => $store->commission_rate,
                    'paid_at' => $at,
                    'picked_up_at' => in_array($status, [OrderStatus::PickedUp, OrderStatus::Settled], true) ? $at : null,
                    'cancelled_at' => $status === OrderStatus::Cancelled ? $at : null,
                    'refunded_at' => $status === OrderStatus::Refunded ? $at : null,
                    'cancel_reason' => $status === OrderStatus::Cancelled ? mb_substr("원본 {$statusText}", 0, 50) : null,
                ]);
                $order->created_at = $at;
                $order->updated_at = $at;
                $order->save();
                foreach ($lines as $line) {
                    $item = $order->items()->make($line);
                    $item->created_at = $at;
                    $item->updated_at = $at;
                    $item->save();
                }

                if ($statusText === '구매취소요청') {
                    $report['cancel_requests'][] = $no;
                }
                $report['created']++;
                $report['total'] += $sum;
            });
        }

        return $report;
    }

    /** "[포장] 아메리카노 (HOT/ICE) / ICE" → "아메리카노 (HOT/ICE)" */
    private function baseName(string $title): string
    {
        $name = (string) preg_replace('/^(\[[^\]]+\]\s*)+/u', '', trim($title));

        return trim((string) preg_replace('/\s*\/\s*(HOT|ICE|ADE)\s*$/iu', '', $name));
    }

    /** 원본 상품 번호로 찾고, 원본에서 지워진 상품이면 숨김 상품으로 만들어 판매 기록을 지킨다 */
    private function product(Store $store, int $productNo, string $title, int $unit, array &$report): Product
    {
        $ref = "dadamda:{$productNo}";
        $product = Product::where('legacy_ref', $ref)->first();
        if ($product && $product->store_id === $store->id) {
            return $product;
        }
        if ($product) {
            throw new \RuntimeException("원본 상품 {$productNo} 이(가) 다른 매장에 들어가 있어요.");
        }
        $report['placeholders'][] = "{$productNo} {$this->baseName($title)}";

        return Product::create([
            'store_id' => $store->id, 'legacy_ref' => $ref, 'name' => mb_substr($this->baseName($title), 0, 100),
            'price' => $unit, 'stock' => null, 'stock_deduct_at' => 'order', 'is_visible' => false,
            'description' => '원본에서 지워진 상품 (판매 기록 보존용)',
        ]);
    }
}
