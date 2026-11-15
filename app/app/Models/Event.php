<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 공연 */
class Event extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'starts_at' => 'datetime',
        'doors_at' => 'datetime',
        'sales_open_at' => 'datetime',
        'max_per_buyer' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function sections(): HasMany
    {
        return $this->hasMany(SeatSection::class)->orderBy('ring')->orderBy('start_deg');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function capacitySlots(): HasMany
    {
        return $this->hasMany(CapacitySlot::class);
    }

    public function salesOpen(): bool
    {
        return ($this->sales_open_at === null || $this->sales_open_at->isPast()) && $this->starts_at->isFuture();
    }

    public function seatsLeft(): int
    {
        return (int) $this->sections->sum(fn (SeatSection $s) => $s->remaining());
    }

    public function lowestPrice(): int
    {
        return (int) $this->sections->min('price');
    }
}
