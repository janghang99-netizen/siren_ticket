<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'store_id', 'name', 'description', 'origin', 'allergens', 'price', 'mileage_points', 'is_alcohol',
        'stock', 'stock_deduct_at', 'is_visible', 'sort', 'blocked_at', 'block_reason',
    ];

    protected $casts = [
        'price' => 'integer', 'stock' => 'integer', 'is_visible' => 'boolean',
        'is_alcohol' => 'boolean', 'blocked_at' => 'datetime',
    ];

    /** 팬에게 보이는 상품: 업체가 판매 중 + 운영사 판매중지 아님 */
    public function scopeSellable($q)
    {
        return $q->where('is_visible', true)->whereNull('blocked_at');
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
