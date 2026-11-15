<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 정산서: 업체 × 기간. 지급액 = 매출 - 수수료 */
class Settlement extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'paid_at' => 'datetime',
        'order_count' => 'integer',
        'gross_amount' => 'integer',
        'commission_amount' => 'integer',
        'payout_amount' => 'integer',
        'refund_count' => 'integer',
        'refund_amount' => 'integer',
        'commission_vat' => 'integer',
        'deduction_amount' => 'integer',
        'payout_due_on' => 'date',
        'tax_invoice_issued_at' => 'datetime',
        'disputed_at' => 'datetime',
        'dispute_replied_at' => 'datetime',
    ];

    public function adjustments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SettlementAdjustment::class);
    }

    /** 지급예정일이 지났는데 미지급이면 지연 일수 (이용계약 제3조: 연 6% 가산 대상 표시) */
    public function overdueDays(): int
    {
        if ($this->status === 'paid' || ! $this->payout_due_on || ! $this->payout_due_on->isPast()) {
            return 0;
        }

        return (int) $this->payout_due_on->copy()->startOfDay()->diffInDays(now()->startOfDay());
    }

    /** 이의제기: 정산서 발행일부터 30일 이내, 한 번 (입점계약 제7조) */
    public function canDispute(): bool
    {
        return $this->disputed_at === null && $this->created_at->copy()->addDays(30)->isFuture();
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function statusLabel(): string
    {
        return $this->status === 'paid' ? '지급완료' : '지급대기';
    }
}
