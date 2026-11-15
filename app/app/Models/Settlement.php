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
    ];

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
