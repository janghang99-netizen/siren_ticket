<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 상품명 번역 (원본 다담다 product_translation) — src_name 이 현재 상품명과 같을 때만 유효 */
class ProductTranslation extends Model
{
    protected $fillable = ['product_id', 'lang', 'src_name', 'trans_name'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
