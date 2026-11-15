<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'store_id', 'name', 'category', 'description', 'image_path', 'origin', 'allergens', 'price', 'mileage_points', 'is_alcohol',
        'stock', 'stock_deduct_at', 'sold_out_at', 'is_visible', 'sort', 'blocked_at', 'block_reason',
    ];

    protected $casts = [
        'price' => 'integer', 'stock' => 'integer', 'is_visible' => 'boolean',
        'is_alcohol' => 'boolean', 'blocked_at' => 'datetime', 'sold_out_at' => 'datetime',
    ];

    /** 팬에게 보이는 상품: 업체가 판매 중 + 운영사 판매중지 아님 */
    public function scopeSellable($q)
    {
        return $q->where('is_visible', true)->whereNull('blocked_at');
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

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class);
    }
}
