<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * 재고 + 재고 원장. 재고가 바뀌는 모든 경로(생산·폐기·재고맞춤·판매·취소복구)가 여기를 지나
 * stock_movements 에 한 줄씩 남긴다 → "지금 재고가 왜 이 숫자인지" 추적 가능.
 * 재고는 옵션에 재고가 있으면 옵션, 아니면 상품 단위로 관리한다. null = 재고 관리 안 함(무제한).
 */
class InventoryService
{
    /** @return array{0:string,1:int}|null */
    public function target(Product $product, ?ProductOption $option): ?array
    {
        if ($option && $option->stock !== null) {
            return ['product_options', $option->id];
        }

        return $product->stock !== null ? ['products', $product->id] : null;
    }

    /**
     * 판매 차감. guarded 면 재고 부족 시 예외(조건부 원자적 UPDATE — 조회 후 차감 경쟁 없음).
     * 재고 관리 안 하는 상품이면 false.
     */
    public function sell(Product $product, ?ProductOption $option, int $qty, bool $guarded, Order $order): bool
    {
        $target = $this->target($product, $option);
        if ($target === null) {
            return false;
        }

        $q = DB::table($target[0])->where('id', $target[1]);
        if ($guarded) {
            $q->where('stock', '>=', $qty);
        }
        if ($q->decrement('stock', $qty) === 0) {
            throw new OrderException("{$product->name}".($option ? " ({$option->name})" : '').' 재고가 부족해요.', 'out_of_stock');
        }
        $this->log($product, $option, 'sale', -$qty, $target, order: $order);

        return true;
    }

    /** 주문 취소·만료로 재고 복구 */
    public function restore(Product $product, ?ProductOption $option, int $qty, Order $order): void
    {
        if ($target = $this->target($product, $option)) {
            DB::table($target[0])->where('id', $target[1])->increment('stock', $qty);
            $this->log($product, $option, 'restore', $qty, $target, order: $order);
        }
    }

    /**
     * 업체 입력: 생산(+) · 폐기(−) · 재고 맞춤(= 목표 수량, 실사·당일 리셋).
     * 재고 관리 안 하던 상품에 생산을 입력하면 그때부터 재고를 관리한다(0에서 시작).
     */
    public function record(Product $product, ?ProductOption $option, string $type, int $qty, ?string $memo, ?int $userId): StockMovement
    {
        if (! in_array($type, ['produce', 'discard', 'adjust'], true)) {
            throw new OrderException('알 수 없는 재고 작업이에요.');
        }
        if ($qty < 0 || ($type !== 'adjust' && $qty === 0)) {
            throw new OrderException('수량을 1 이상으로 적어 주세요.');
        }
        if ($option && $option->product_id !== $product->id) {
            throw new OrderException('이 상품의 옵션이 아니에요.');
        }

        return DB::transaction(function () use ($product, $option, $type, $qty, $memo, $userId) {
            // 옵션이 있는 상품은 옵션 단위로만 입력
            if (! $option && $product->options()->exists()) {
                throw new OrderException('옵션이 있는 상품은 옵션을 골라 입력해 주세요.');
            }
            $table = $option ? 'product_options' : 'products';
            $id = $option ? $option->id : $product->id;
            $current = DB::table($table)->where('id', $id)->lockForUpdate()->value('stock');

            if ($type === 'discard' && $current !== null && $qty > $current) {
                throw new OrderException("남은 재고({$current}개)보다 많이 폐기할 수 없어요.");
            }

            $new = match ($type) {
                'produce' => ($current ?? 0) + $qty,
                'discard' => ($current ?? 0) - $qty,
                'adjust' => $qty,
            };
            DB::table($table)->where('id', $id)->update(['stock' => $new, 'updated_at' => now()]);

            return $this->log($product, $option, $type, $new - ($current ?? 0), [$table, $id], $memo, $userId);
        });
    }

    private function log(Product $product, ?ProductOption $option, string $type, int $qty, array $target, ?string $memo = null, ?int $userId = null, ?Order $order = null): StockMovement
    {
        return StockMovement::create([
            'store_id' => $product->store_id,
            'product_id' => $product->id,
            'product_option_id' => $target[0] === 'product_options' ? $target[1] : null,
            'order_id' => $order?->id,
            'type' => $type,
            'qty' => $qty,
            'stock_after' => DB::table($target[0])->where('id', $target[1])->value('stock'),
            'memo' => $memo,
            'user_id' => $userId,
        ]);
    }
}
