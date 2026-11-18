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

    protected $casts = [
        'tiers' => 'array', 'plan' => 'array', 'image_w' => 'integer', 'image_h' => 'integer',
        'crop_x' => 'float', 'crop_y' => 'float', 'crop_w' => 'float', 'crop_h' => 'float',
    ];

    public function venueRecord(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Venue::class, 'venue_id');
    }

    /**
     * 팬에게 보여줄 영역(배치도만 — 범례·요약표·제목은 잘라 냄).
     * 바깥 틀은 잘라 낸 영역의 비율, 안쪽은 원본 이미지를 키워 영역만 보이게 옮긴다. 구역 박스 좌표(원본 기준)는 그대로 쓴다.
     */
    public function frameStyle(): string
    {
        return sprintf('aspect-ratio: %.1f / %.1f', max(1, $this->crop_w * $this->image_w), max(1, $this->crop_h * $this->image_h));
    }

    public function innerStyle(): string
    {
        $w = max(.01, $this->crop_w);
        $h = max(.01, $this->crop_h);

        return sprintf('position:absolute;width:%.3f%%;left:%.3f%%;top:%.3f%%', 100 / $w, -100 * $this->crop_x / $w, -100 * $this->crop_y / $h);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(SeatMapSection::class)->orderBy('sort')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? route('media.seat-map', basename($this->image_path)) : null;
    }

    /** 편집기로 그린 도면 (배치도 이미지 위 박스가 아니라 무대·구역 모양을 그린 것) */
    public function isPlan(): bool
    {
        return $this->kind === 'plan';
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
