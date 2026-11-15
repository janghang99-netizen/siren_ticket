<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 매장별 상품 카테고리 (커피·디저트·음료 …) — 이름·순서·숨기기 */
class ProductCategory extends Model
{
    /** 카테고리가 없는 상품을 묶어 보여 줄 때의 이름 */
    public const UNCATEGORIZED = '기타';

    protected $fillable = ['store_id', 'name', 'sort', 'is_visible'];

    protected $casts = ['sort' => 'integer', 'is_visible' => 'boolean'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}
