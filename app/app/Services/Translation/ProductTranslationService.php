<?php

namespace App\Services\Translation;

use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\TranslationFix;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * 상품명 번역 사전 (원본 다담다 inc/lib_translate.php + ajax_translate_dict.php).
 *  - 대기: 번역이 없거나, 번역 당시 이름(src_name)과 지금 상품명이 다른 상품
 *  - 일괄: 25개씩 묶어 Claude 에 보내고, 한 번에 최대 150개
 *  - 사전: 지금 상품명과 src_name 이 같은 번역만 — 이름을 바꾸면 옛 번역은 바로 빠지고 구글 번역으로 돌아간다
 */
class ProductTranslationService
{
    public const LANGS = ['en' => 'English', 'ja' => '日本語', 'zh' => '中文'];

    public const BATCH = 25;

    public const MAX_PER_RUN = 150;

    public function __construct(private readonly ProductNameTranslator $translator) {}

    public function ready(): bool
    {
        return $this->translator->ready();
    }

    /** 번역 대기 상품 */
    public function pending(string $lang, ?int $limit = null): Collection
    {
        $q = Product::query()
            ->leftJoin('product_translations as pt', fn ($j) => $j->on('pt.product_id', '=', 'products.id')->where('pt.lang', $lang))
            ->where(fn ($w) => $w->whereNull('pt.id')->orWhereColumn('pt.src_name', '!=', 'products.name'))
            ->where('products.name', '!=', '')
            ->orderBy('products.id')
            ->select('products.id', 'products.name');

        return $limit ? $q->limit($limit)->get() : $q->get();
    }

    /** 언어별 현황 */
    public function status(): array
    {
        $total = Product::where('name', '!=', '')->count();
        $out = [];
        foreach (array_keys(self::LANGS) as $lang) {
            $pending = $this->pending($lang)->count();
            $out[$lang] = ['total' => $total, 'done' => $total - $pending, 'pending' => $pending];
        }

        return $out;
    }

    /**
     * 대기 상품을 번역해 저장한다 (같은 이름은 한 번만 보내 함께 쓴다).
     *
     * @return array{translated:int, errors:list<string>}
     */
    public function run(string $lang, int $max = self::MAX_PER_RUN): array
    {
        $max = max(1, min($max, self::MAX_PER_RUN));
        $translated = 0;
        $errors = [];

        foreach ($this->pending($lang, $max)->chunk(self::BATCH) as $chunk) {
            $names = $chunk->pluck('name')->unique()->values()->all();
            try {
                $result = array_combine($names, $this->translator->translate($names, $lang));
            } catch (TranslationFailed $e) {
                $errors[] = $e->getMessage();

                continue;
            }
            foreach ($chunk as $p) {
                $trans = $result[$p->name] ?? '';
                if ($trans === '') {
                    continue;
                }
                ProductTranslation::updateOrCreate(
                    ['product_id' => $p->id, 'lang' => $lang],
                    ['src_name' => $p->name, 'trans_name' => $trans],
                );
                $translated++;
            }
        }
        $this->forget($lang);

        return ['translated' => $translated, 'errors' => $errors];
    }

    /** 연결 테스트 — 저장하지 않고 상품 3개만 번역해 본다 */
    public function test(string $lang): array
    {
        $names = Product::where('name', '!=', '')->orderBy('id')->limit(3)->pluck('name')->unique()->values()->all()
            ?: ['아메리카노 (ICE)', '떡볶이', '가소사'];

        return array_combine($names, $this->translator->translate($names, $lang));
    }

    /**
     * 화면용 사전 — {products: {한국어 상품명: 번역}, fixes: [[잘못된 번역, 올바른 번역], ...]}
     * 10분 캐시. 번역·교정 사전이 바뀌면 캐시를 바로 지운다.
     */
    public function dict(string $lang): array
    {
        return Cache::remember($this->key($lang), 600, function () use ($lang) {
            $products = ProductTranslation::query()
                ->join('products', 'products.id', '=', 'product_translations.product_id')
                ->where('product_translations.lang', $lang)
                ->whereColumn('product_translations.src_name', 'products.name')
                ->pluck('product_translations.trans_name', 'products.name')
                ->all();
            $fixes = TranslationFix::where('lang', $lang)->get(['wrong', 'right'])
                ->sortByDesc(fn ($f) => mb_strlen($f->wrong))   // 긴 말부터 바꿔야 짧은 말이 먼저 먹지 않는다
                ->map(fn ($f) => [$f->wrong, $f->right])->values()->all();

            return ['products' => $products, 'fixes' => $fixes];
        });
    }

    public function forget(string $lang): void
    {
        Cache::forget($this->key($lang));
    }

    private function key(string $lang): string
    {
        return "translate.dict.{$lang}";
    }
}
