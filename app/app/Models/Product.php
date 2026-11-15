<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'store_id', 'category_id', 'name', 'description', 'image_path', 'origin', 'allergens', 'price', 'mileage_points', 'is_alcohol',
        'stock', 'stock_deduct_at', 'sold_out_at', 'is_visible', 'sort', 'blocked_at', 'block_reason',
    ];

    protected $casts = [
        'price' => 'integer', 'stock' => 'integer', 'is_visible' => 'boolean',
        'is_alcohol' => 'boolean', 'blocked_at' => 'datetime', 'sold_out_at' => 'datetime',
    ];

    /** 팬에게 보이는 상품: 업체가 판매 중 + 운영사 판매중지 아님 + 숨긴 카테고리가 아님 */
    public function scopeSellable($q)
    {
        return $q->where('is_visible', true)->whereNull('blocked_at')
            ->where(fn ($w) => $w->whereNull('category_id')->orWhereHas('category', fn ($c) => $c->where('is_visible', true)));
    }

    /**
     * 메뉴 순서: 카테고리 순서 → 상품 순서 → 등록 순. 카테고리 없는 상품('기타')은 맨 뒤.
     *
     * @param  \Illuminate\Support\Collection<int, self>  $products  category 관계를 불러온 상품들
     */
    public static function menuOrder(\Illuminate\Support\Collection $products): \Illuminate\Support\Collection
    {
        return $products->sortBy([
            fn ($a, $b) => ($a->category?->sort ?? PHP_INT_MAX) <=> ($b->category?->sort ?? PHP_INT_MAX),
            fn ($a, $b) => ($a->category_id ?? PHP_INT_MAX) <=> ($b->category_id ?? PHP_INT_MAX),
            fn ($a, $b) => $a->sort <=> $b->sort,
            fn ($a, $b) => $a->id <=> $b->id,
        ])->values();
    }

    /** 화면에 보일 카테고리 이름 (없으면 '기타') */
    public function categoryName(): string
    {
        return $this->category?->name ?? ProductCategory::UNCATEGORIZED;
    }

    /** 상품 이미지 주소 (없으면 null) */
    public function imageUrl(): ?string
    {
        return $this->image_path ? route('media.product', basename($this->image_path)) : null;
    }

    /** 지금 품절인지: 보드에서 품절로 켰거나, 재고가 0 이거나, 옵션이 모두 품절 */
    public function isSoldOut(): bool
    {
        if ($this->sold_out_at) {
            return true;
        }
        if ($this->relationLoaded('options') && $this->options->isNotEmpty()) {
            return $this->options->every(fn ($o) => $o->stock !== null && $o->stock <= 0);
        }

        return $this->stock !== null && $this->stock <= 0;
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    protected $attributes = ['stock_deduct_at' => 'order', 'is_visible' => true, 'sort' => 0];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class);
    }
}
