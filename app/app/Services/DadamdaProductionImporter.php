<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * 원본 다담다 생산(입고) 기록 → product_productions. 상품 이관 다음에 한다.
 *
 * - 지난 기록(이력)으로만 넣는다: 재고를 바꾸지 않고(상품 이관 때 원본 현재 재고를 이미 넣음), EIS 로 다시 보내지 않는다.
 * - 원본 DB 내보내기(product_production 표)에는 상품 번호(product_no)가 있다 → 이관 상품의 legacy_ref 로 정확히 찾는다.
 *   원본은 상품 이름을 여러 번 바꿨으므로(예: 초록대파 → 진도대파 소금빵) 번호가 있으면 이름은 보지 않는다.
 * - 번호가 없는 기록(생산 화면에서 긁은 것)만 이름으로 찾는다: 같은 이름이 여럿이면 판매 중인 상품, 그다음 원본 번호가 큰(최근) 상품.
 * - 원본 기록 번호를 legacy_ref 에 남겨 다시 가져와도 같은 기록을 두 번 넣지 않는다.
 */
class DadamdaProductionImporter
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{created:int, skipped_existing:int, qty:int, errors:array<int,string>}
     */
    public function import(array $rows, Store $store): array
    {
        $report = ['created' => 0, 'skipped_existing' => 0, 'qty' => 0, 'errors' => []];
        $products = Product::where('store_id', $store->id)->where('legacy_ref', 'like', 'dadamda:%')->with('options')->get();

        foreach ($rows as $r) {
            $no = (int) ($r['no'] ?? 0);
            $ref = "dadamda:{$no}";
            if ($no <= 0 || empty($r['date']) || ! isset($r['qty'])) {
                $report['errors'][] = "생산 {$no}: 번호·날짜·수량이 없어 건너뜀";

                continue;
            }
            if (DB::table('product_productions')->where('legacy_ref', $ref)->exists()) {
                $report['skipped_existing']++;

                continue;
            }
            $product = ! empty($r['product_no'])
                ? $products->firstWhere('legacy_ref', 'dadamda:'.(int) $r['product_no'])
                : $this->findProduct($products, (string) ($r['name'] ?? ''));
            if (! $product) {
                $report['errors'][] = "생산 {$no}: 상품 '{$r['name']}' 을(를) 이 매장에서 찾지 못해 건너뜀";

                continue;
            }
            $option = null;
            if (! empty($r['option'])) {
                $option = $product->options->firstWhere('name', $r['option']);
                if (! $option) {
                    $report['errors'][] = "생산 {$no}: 옵션 '{$r['option']}' 이(가) 없어 건너뜀";

                    continue;
                }
            }

            $at = ! empty($r['at']) ? (string) $r['at'] : $r['date'].' 00:00:00';
            DB::table('product_productions')->insert([
                'store_id' => $store->id,
                'legacy_ref' => $ref,
                'product_id' => $product->id,
                'product_option_id' => $option?->id,
                'production_date' => $r['date'],
                'qty' => max(0, (int) $r['qty']),
                'memo' => ($m = trim((string) ($r['memo'] ?? ''))) !== '' ? mb_substr($m, 0, 255) : '원본 생산 기록',
                'user_id' => null,
                'webhook_sent' => (bool) ($r['eis_sent'] ?? true),   // 원본이 이미 EIS 로 보냄 — 다시 보내지 않는다
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            $report['created']++;
            $report['qty'] += max(0, (int) $r['qty']);
        }

        return $report;
    }

    private function findProduct($products, string $name): ?Product
    {
        $key = fn (string $s) => preg_replace('/\s+/u', '', mb_strtolower($s));

        return $products->filter(fn (Product $p) => $key($p->name) === $key($name))
            ->sortByDesc(fn (Product $p) => [(int) $p->is_visible, (int) substr((string) $p->legacy_ref, 8)])
            ->first();
    }
}
