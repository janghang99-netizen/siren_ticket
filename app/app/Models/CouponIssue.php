<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 고객이 받은 쿠폰 한 장 */
class CouponIssue extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime', 'seq' => 'integer'];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function usable(): bool
    {
        return $this->status === 'issued' && (! $this->expires_at || $this->expires_at->isFuture())
            && $this->coupon->is_active && (! $this->coupon->ends_at || $this->coupon->ends_at->isFuture());
    }

    public function statusLabel(): string
    {
        if ($this->status === 'used') {
            return '사용함';
        }

        return $this->usable() ? '사용 가능' : '기간 만료';
    }
}
