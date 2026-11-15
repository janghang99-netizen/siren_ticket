<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 차기 정산 공제 항목 (정산 후 환불분, 판매자 귀책 비용). amount 양수 = 지급액에서 차감 */
class SettlementAdjustment extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount' => 'integer'];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }
}
