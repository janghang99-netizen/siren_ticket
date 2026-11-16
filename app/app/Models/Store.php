<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    protected $fillable = ['vendor_id', 'code', 'name', 'area', 'address', 'hours', 'holidays', 'commission_rate', 'is_open'];

    public function vendor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    protected $casts = ['is_open' => 'boolean', 'commission_rate' => 'decimal:2'];

    /** DB 기본값과 동일 — create() 직후 메모리 모델에도 적용되게 */
    protected $attributes = ['is_open' => true, 'commission_rate' => '8.50'];

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function capacitySlots(): HasMany
    {
        return $this->hasMany(CapacitySlot::class);
    }
}
