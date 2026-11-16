<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 좌석표 = 좌석 배치도 이미지 + 등급(색·가격) + 구역(이미지 위 박스, 열 × 열당 좌석).
 * 같은 공연장이라도 무대 배치마다 따로 만든다 (가로 무대 / 세로 무대 …).
 */
class SeatMap extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['tiers' => 'array', 'image_w' => 'integer', 'image_h' => 'integer'];

    public function sections(): HasMany
    {
        return $this->hasMany(SeatMapSection::class)->orderBy('sort')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function imageUrl(): string
    {
        return route('media.seat-map', basename($this->image_path));
    }

    /** @return array{key:string,name:string,color:string,price:int,seats:int}|null */
    public function tier(string $key): ?array
    {
        return collect($this->tiers)->firstWhere('key', $key);
    }

    public function totalSeats(): int
    {
        return (int) $this->sections->sum(fn (SeatMapSection $s) => $s->capacity());
    }
}
