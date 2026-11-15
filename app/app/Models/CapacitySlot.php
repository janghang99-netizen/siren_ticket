<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapacitySlot extends Model
{
    protected $fillable = ['event_id', 'store_id', 'slot', 'capacity', 'used', 'closes_at', 'is_closed'];

    protected $casts = [
        'capacity' => 'integer',
        'used' => 'integer',
        'closes_at' => 'datetime',
        'is_closed' => 'boolean',
    ];

    protected $attributes = ['used' => 0, 'is_closed' => false];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function remaining(): int
    {
        return max(0, $this->capacity - $this->used);
    }

    /** 카운트 소진 OR 시간 마감 OR 수동 마감 — 먼저 오는 조건으로 닫힌다. */
    public function isOpen(): bool
    {
        return ! $this->is_closed
            && $this->used < $this->capacity
            && ($this->closes_at === null || $this->closes_at->isFuture());
    }
}
