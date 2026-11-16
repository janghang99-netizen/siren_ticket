<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 공연장 — 무대 배치별 좌석표 여러 개를 갖고, 공연은 공연장과 그 좌석표 하나를 고른다 */
class Venue extends Model
{
    protected $guarded = ['id'];

    public function seatMaps(): HasMany
    {
        return $this->hasMany(SeatMap::class)->latest('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class)->orderByDesc('starts_at');
    }
}
