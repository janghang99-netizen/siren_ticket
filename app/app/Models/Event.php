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

    /** 작품 없이 만든 회차(시드·이전 방식)는 같은 이름의 작품을 붙인다 — 메인·목록은 작품 단위로 보여 주므로 */
    protected static function booted(): void
    {
        static::creating(function (Event $e) {
            if ($e->production_id) {
                return;
            }
            $e->production_id = Production::create([
                'slug' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(10)),
                'title' => (string) $e->name, 'artist' => (string) ($e->artist ?? ''),
                'venue' => (string) ($e->venue ?? ''), 'venue_id' => $e->venue_id,
                'theme_color' => $e->theme_color ?: '#6C4BFF',
            ])->id;
        });
    }

    /** 이 회차가 속한 작품 (포스터·장르·기간) */
    public function production(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Production::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(SeatSection::class)->orderBy('ring')->orderBy('start_deg')->orderBy('id');
    }

    /** 좌석표(이미지 배치도)를 쓰는 공연이면 그 좌석표. 없으면 기본 아레나 보울 좌석도. */
    /** 공연장 목록의 공연장 (venue 글자 칼럼은 화면 표시용 이름) */
    public function venueRecord(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Venue::class, 'venue_id');
    }

    public function seatMap(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SeatMap::class);
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
        return ! $this->isCancelled()
            && ($this->sales_open_at === null || $this->sales_open_at->isPast()) && $this->starts_at->isFuture();
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
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
