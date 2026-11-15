<?php

namespace App\Services\Translation;

/** 한국어 상품명 묶음 → 대상 언어 상품명 (같은 순서, 같은 개수) */
interface ProductNameTranslator
{
    /** API 키 등 준비가 됐는지 */
    public function ready(): bool;

    /**
     * @param  list<string>  $names  한국어 상품명
     * @param  string  $lang  en / ja / zh
     * @return list<string>
     *
     * @throws TranslationFailed
     */
    public function translate(array $names, string $lang): array;
}
