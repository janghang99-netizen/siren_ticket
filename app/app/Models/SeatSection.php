<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeatSection extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'price' => 'integer',
        'capacity' => 'integer',
        'sold' => 'integer',
        'ring' => 'integer',
        'start_deg' => 'integer',
        'end_deg' => 'integer',
        'x' => 'float', 'y' => 'float', 'w' => 'float', 'h' => 'float',
        'rows' => 'integer', 'seats_per_row' => 'integer',
    ];

    /** 좌석표 공연 구역: 열 × 열당 좌석 격자가 있어 팬이 좌석을 직접 고른다. (보울 구역은 자동 배정) */
    public function hasSeatGrid(): bool
    {
        return $this->rows !== null && $this->seats_per_row !== null;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function remaining(): int
    {
        return max(0, $this->capacity - $this->sold);
    }

    /**
     * 보울 좌석도의 호(arc) SVG path. 중심(200,200), 무대는 위쪽(12시).
     * 각도 0 = 무대 반대편(6시), 시계방향. ring 0 = 플로어(안쪽) … 2 = 2층(바깥).
     */
    public function arcPath(): string
    {
        [$inner, $outer] = [[70, 110], [118, 152], [160, 192]][$this->ring] ?? [160, 192];
        $a0 = deg2rad($this->start_deg + 90);
        $a1 = deg2rad($this->end_deg + 90);
        $large = ($this->end_deg - $this->start_deg) > 180 ? 1 : 0;
        $p = fn ($r, $a) => sprintf('%.1f %.1f', 200 + $r * cos($a), 200 + $r * sin($a));

        return 'M '.$p($outer, $a0)
            .' A '.$outer.' '.$outer.' 0 '.$large.' 1 '.$p($outer, $a1)
            .' L '.$p($inner, $a1)
            .' A '.$inner.' '.$inner.' 0 '.$large.' 0 '.$p($inner, $a0).' Z';
    }
}
