<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 좌석표의 구역 하나 — 이미지 위 박스(0~1 비율) + 열 × 열당 좌석 */
class SeatMapSection extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'x' => 'float', 'y' => 'float', 'w' => 'float', 'h' => 'float',
        'rows' => 'integer', 'seats_per_row' => 'integer', 'sort' => 'integer', 'layout' => 'array',
    ];

    public function seatMap(): BelongsTo
    {
        return $this->belongsTo(SeatMap::class);
    }

    /** 좌석 수 — 좌석 리스트(layout)가 있으면 실제 좌석 수, 없으면 열 × 열당 좌석 */
    public function capacity(): int
    {
        return $this->layout
            ? array_sum(array_map(fn ($r) => count($r['seats'] ?? []), $this->layout))
            : $this->rows * $this->seats_per_row;
    }
}
