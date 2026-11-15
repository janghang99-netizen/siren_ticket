<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 쿠폰. 원본 다담다에는 정액 상품쿠폰과 스탬프 무료쿠폰만 있었고, 여기서 일반 할인쿠폰으로 넓혔다.
 * 비용 부담: issuer = platform 이면 운영사가, vendor 면 업체가 낸다 (업체 쿠폰은 정산에서 뺀다).
 */
class Coupon extends Model
{
    public const KINDS = ['fixed' => '정액 할인', 'percent' => '정률 할인', 'free_item' => '무료 상품'];

    public const SCOPES = ['order' => '매장 주문', 'ticket' => '공연 티켓'];

    protected $guarded = ['id'];

    protected $attributes = ['is_active' => true, 'is_public' => false, 'source' => 'manual', 'min_amount' => 0, 'value' => 0, 'issued_count' => 0, 'used_count' => 0];

    protected $casts = [
        'is_active' => 'boolean', 'is_public' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime',
        'value' => 'integer', 'max_discount' => 'integer', 'min_amount' => 'integer', 'total_limit' => 'integer',
        'issued_count' => 'integer', 'used_count' => 'integer', 'valid_days' => 'integer',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(CouponIssue::class);
    }

    /** 지금 받을 수 있는 기간·수량인가 */
    public function claimable(): bool
    {
        return $this->is_active
            && (! $this->starts_at || $this->starts_at->isPast())
            && (! $this->ends_at || $this->ends_at->isFuture())
            && ($this->total_limit === null || $this->issued_count < $this->total_limit);
    }

    /** 이 쿠폰이 이 매장 주문 / 이 공연 예매에 쓸 수 있는가 */
    public function appliesTo(?Store $store, ?Event $event): bool
    {
        if ($this->scope === 'order') {
            return $store !== null
                && (! $this->store_id || $this->store_id === $store->id)
                && (! $this->vendor_id || $this->vendor_id === $store->vendor_id);
        }

        return $event !== null && (! $this->event_id || $this->event_id === $event->id);
    }

    /**
     * 할인액. free_item 은 그 상품 1개 값(옵션 제외)을 깎는다.
     *
     * @param  array<int, int>  $unitPrices  product_id => 단가 (free_item 판단용)
     */
    public function discountFor(int $amount, array $unitPrices = []): int
    {
        $d = match ($this->kind) {
            'fixed' => $this->value,
            'percent' => (int) floor($amount * $this->value / 100 / 10) * 10,
            'free_item' => $unitPrices[$this->product_id] ?? 0,
            default => 0,
        };
        if ($this->max_discount) {
            $d = min($d, $this->max_discount);
        }

        return max(0, min($d, $amount));
    }

    public function benefitLabel(): string
    {
        return match ($this->kind) {
            'fixed' => number_format($this->value).'원 할인',
            'percent' => $this->value.'% 할인'.($this->max_discount ? ' (최대 '.number_format($this->max_discount).'원)' : ''),
            'free_item' => ($this->product?->name ?? '상품').' 1개 무료',
            default => '',
        };
    }

    public function funderLabel(): string
    {
        return $this->issuer === 'platform' ? '운영사 부담' : '업체 부담';
    }
}
