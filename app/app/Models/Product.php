<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['store_id', 'name', 'price', 'stock', 'stock_deduct_at', 'is_visible', 'sort'];

    protected $casts = ['price' => 'integer', 'stock' => 'integer', 'is_visible' => 'boolean'];

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
