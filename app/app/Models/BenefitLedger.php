<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 혜택 원장 한 줄 — 추가만 하고 고치지 않는다 (해시 체인). BenefitLedgerService::append 로만 쓴다. */
class BenefitLedger extends Model
{
    protected $table = 'benefit_ledger';

    public const UPDATED_AT = null;

    public const KINDS = ['mileage' => '마일리지', 'stamp' => '스탬프', 'coupon' => '쿠폰'];

    public const TYPES = [
        'earn' => '적립', 'use' => '사용', 'restore' => '사용 취소', 'revoke' => '적립 회수',
        'adjust' => '수동 조정', 'issue' => '발급', 'redeem' => '보상 교환',
    ];

    protected $guarded = ['id'];

    protected $casts = ['amount' => 'integer', 'balance_after' => 'integer', 'created_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function anchor(): BelongsTo
    {
        return $this->belongsTo(LedgerAnchor::class, 'anchor_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
