<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'price' => 'integer',
        'row_no' => 'integer',
        'seat_no' => 'integer',
        'paid_at' => 'datetime',
        'checked_in_at' => 'datetime',
        'terms_agreed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'cancel_fee' => 'integer',
        'refund_amount' => 'integer',
        'coupon_discount' => 'integer',
        'mileage_used' => 'integer',
        'paid_amount' => 'integer',
        'benefit_granted_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** NFT 티켓 (회원 예매분만) */
    public function token(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TicketToken::class);
    }

    /**
     * 구매자 취소 수수료율 (소비자분쟁해결기준·공연): 관람일 10일 전까지 0%, 9~7일 전 10%,
     * 6~3일 전 20%, 2~1일 전 30%, 당일은 취소 불가(null).
     */
    public function cancelFeeRate(?\Illuminate\Support\Carbon $at = null): ?int
    {
        $at ??= now();
        $days = (int) $at->copy()->startOfDay()->diffInDays($this->event->starts_at->copy()->startOfDay(), false);

        return match (true) {
            $days >= 10 => 0,
            $days >= 7 => 10,
            $days >= 3 => 20,
            $days >= 1 => 30,
            default => null,
        };
    }

    public function statusLabel(): string
    {
        return ['paid' => '예매 완료', 'used' => '입장 완료', 'cancelled' => '취소'][$this->status] ?? $this->status;
    }

    public function getRouteKeyName(): string
    {
        return 'ticket_no';
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(SeatSection::class, 'seat_section_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function seatLabel(): string
    {
        return "{$this->section->code}구역 {$this->row_no}열 {$this->seat_no}번";
    }
}
