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
    ];

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
