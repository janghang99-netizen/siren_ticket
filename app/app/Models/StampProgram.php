<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 스탬프 프로그램 (원본 stamp_config).
 *  - owner=store: 매장 주문 수령 시 메뉴 1개당 1개 (업체 운영)
 *  - owner=platform: 공연 관람(티켓 1매)당 1개 (운영사 운영) — 한 개만 둔다
 * need_count 개 모이면 reward_coupon 1장을 준다.
 */
class StampProgram extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['is_active' => true, 'need_count' => 10, 'min_amount' => 0];

    protected $casts = ['is_active' => 'boolean', 'need_count' => 'integer', 'min_amount' => 'integer'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function rewardCoupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'reward_coupon_id');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(StampCard::class);
    }

    public static function platform(): ?self
    {
        return static::where('owner', 'platform')->first();
    }
}
