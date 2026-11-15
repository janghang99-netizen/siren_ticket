<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 좌석 티켓 NFT (1티켓 = 1토큰) */
class TicketToken extends Model
{
    public const STATUSES = ['minted' => '발행됨', 'used' => '입장 완료', 'burned' => '소각(취소)'];

    protected $guarded = ['id'];

    protected $casts = ['transferable' => 'boolean', 'minted_at' => 'datetime', 'used_at' => 'datetime', 'burned_at' => 'datetime'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public static function short(string $hex): string
    {
        return strlen($hex) > 12 ? substr($hex, 0, 6).'…'.substr($hex, -4) : $hex;
    }
}
