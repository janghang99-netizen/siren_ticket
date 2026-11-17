<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * 원본 다담다(dadamda.store) 상품 → 새 구조로 하나씩 맞춰 가져오기.
 *
 * - 원본 2차 분류(베이커리류·커피류…) → 매장 상품 분류
 * - 판매중 → 보임, 판매중지 → 숨김 (지우지 않는다)
 * - 원본 재고 1만 이상은 사실상 '무제한'으로 쓰던 값이라 재고 관리 안 함(null)으로 둔다
 * - 고르는 옵션(HOT/ICE…) × 추가 옵션(샷 추가 +500원…) → 새 옵션 하나씩 (예: "ICE + 샷 추가")
 * - 원본 번호를 legacy_ref 에 남겨 다시 가져와도 같은 상품을 고친다 (재고는 처음 한 번만)
 */
class DadamdaProductImporter
{
    public const UNLIMITED_FROM = 10000;

    public const IMAGE_HOST = 'dadamda.store';

    /** 고르는 옵션 보여 줄 순서 */
    private const CHOICE_ORDER = ['HOT' => 1, 'ICE' => 2, 'ADE' => 3];

    public function __construct(private InventoryService $inventory, private ProductImageStore $images) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{created:int, updated:int, images:int, errors:array<int,string>}
     */
    public function import(array $items, Store $store, bool $withImages = false): array
    {
        $report = ['created' => 0, 'updated' => 0, 'images' => 0, 'errors' => []];

        foreach ($items as $item) {
            $no = (int) ($item['no'] ?? 0);
            $name = trim((string) ($item['name'] ?? ''));
            if ($no <= 0 || $name === '' || ! is_numeric($item['price'] ?? null)) {
                $report['errors'][] = "원본 {$no}: 번호·상품명·가격이 없어 건너뜀";

                continue;
            }
            $ref = "dadamda:{$no}";
            $existing = Product::where('legacy_ref', $ref)->first();
            if ($existing && $existing->store_id !== $store->id) {
                $report['errors'][] = "원본 {$no}: 이미 다른 매장(#{$existing->store_id})에 들어가 있어 건너뜀";

                continue;
            }

            $product = DB::transaction(function () use ($item, $store, $existing, $ref, $name) {
                $fields = [
                    'category_id' => $this->category($store, (string) ($item['category'] ?? ''))?->id,
                    'name' => mb_substr($name, 0, 100),
                    'description' => ($d = trim((string) ($item['hongbo'] ?? ''))) !== '' ? mb_substr($d, 0, 500) : null,
                    'price' => max(0, (int) $item['price']),
                    'stock_deduct_at' => ($item['stock_change'] ?? '') === '상품주문시' ? 'order' : 'payment',
                    'is_visible' => ($item['status'] ?? '') === '판매중',
                ];
                $options = $this->options($item['choices'] ?? [], $item['addons'] ?? []);

                if ($existing) {
                    $existing->update($fields);
                    $product = $existing;
                } else {
                    $product = Product::create($fields + ['store_id' => $store->id, 'legacy_ref' => $ref, 'stock' => null]);
                }

                foreach ($options as [$optName, $extra]) {
                    $product->options()->updateOrCreate(['name' => $optName], ['extra_price' => $extra]);
                }

                // 재고: 새로 만들 때만, 옵션 없는 상품만, 실제로 세던 수량(1만 미만)만 — 재고 원장에 '재고 맞춤'으로 남긴다
                $stock = (int) ($item['stock'] ?? 0);
                if (! $existing && ! $options && $stock < self::UNLIMITED_FROM) {
                    $this->inventory->record($product, null, 'adjust', max(0, $stock), '다담다 이관 (원본 재고)', null);
                }

                return $product;
            });
            $existing ? $report['updated']++ : $report['created']++;

            if ($withImages && ! $product->image_path && ($url = $this->firstImage($item['images'] ?? []))) {
                if ($path = $this->downloadImage($url)) {
                    $product->update(['image_path' => $path]);
                    $report['images']++;
                } else {
                    $report['errors'][] = "원본 {$no}: 사진을 받지 못함";
                }
            }
        }

        return $report;
    }

    /** "에이드/ 티류" → "에이드/티류" 로 다듬어 매장 분류를 찾거나 만든다 */
    private function category(Store $store, string $name): ?ProductCategory
    {
        $name = trim((string) preg_replace('/\s*\/\s*/u', '/', $name));
        if ($name === '' || preg_match('/^-+$/', $name)) {
            return null;
        }

        return ProductCategory::firstOrCreate(
            ['store_id' => $store->id, 'name' => mb_substr($name, 0, 30)],
            ['sort' => ProductCategory::where('store_id', $store->id)->count(), 'is_visible' => true],
        );
    }

    /**
     * @param  array<int,string>  $choices  예: ["ICE（+0원)", "HOT（+0원)"]
     * @param  array<int,string>  $addons  예: ["샷 추가 （+500원)"]
     * @return array<int, array{0:string, 1:int}>
     */
    public function options(array $choices, array $addons): array
    {
        $choices = array_values(array_filter(array_map([$this, 'parseOption'], $choices)));
        $addons = array_values(array_filter(array_map([$this, 'parseOption'], $addons)));
        if (! $choices && ! $addons) {
            return [];
        }
        usort($choices, fn ($a, $b) => (self::CHOICE_ORDER[$a[0]] ?? 9) <=> (self::CHOICE_ORDER[$b[0]] ?? 9));
        if (! $choices) {
            $choices = [['기본', 0]];
        }

        $out = [];
        foreach ($choices as [$c, $cExtra]) {
            $out[] = [mb_substr($c, 0, 30), $cExtra];
            foreach ($addons as [$a, $aExtra]) {
                $out[] = [mb_substr("{$c} + {$a}", 0, 30), $cExtra + $aExtra];
            }
        }

        return $out;
    }

    /** "샷 추가 （+500원)" → ["샷 추가", 500] */
    private function parseOption(mixed $text): ?array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(.*?)\s*[（(]\s*\+?\s*([\d,]+)\s*원\s*[)）]\s*$/u', $text, $m)) {
            return [trim($m[1]), (int) str_replace(',', '', $m[2])];
        }

        return [$text, 0];
    }

    private function firstImage(array $urls): ?string
    {
        foreach ($urls as $url) {
            if (parse_url((string) $url, PHP_URL_HOST) === self::IMAGE_HOST && parse_url((string) $url, PHP_URL_SCHEME) === 'https') {
                return (string) $url;
            }
        }

        return null;
    }

    /** 원본 사진을 받아 새 시스템 방식(긴 변 1000px WEBP)으로 저장 */
    private function downloadImage(string $url): ?string
    {
        try {
            $res = Http::timeout(20)->get($url);
        } catch (\Throwable) {
            return null;
        }
        $type = (string) $res->header('Content-Type');
        if (! $res->successful() || ! str_starts_with($type, 'image/') || strlen($res->body()) > 10 * 1024 * 1024) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ddm');
        file_put_contents($tmp, $res->body());
        try {
            return $this->images->store(new UploadedFile($tmp, basename((string) parse_url($url, PHP_URL_PATH)), $type, null, true));
        } finally {
            @unlink($tmp);
        }
    }
}
