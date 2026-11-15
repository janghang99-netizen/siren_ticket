<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/** 입점업체 — 여러 매장을 운영한다 */
class Vendor extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['commission_rate' => 'decimal:2'];

    protected $attributes = ['commission_rate' => '8.50', 'status' => 'active'];

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function orders(): HasManyThrough
    {
        return $this->hasManyThrough(Order::class, Store::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
