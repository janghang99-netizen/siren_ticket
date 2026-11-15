<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    protected $fillable = ['code', 'name', 'commission_rate', 'is_open'];

    protected $casts = ['is_open' => 'boolean', 'commission_rate' => 'decimal:2'];

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
