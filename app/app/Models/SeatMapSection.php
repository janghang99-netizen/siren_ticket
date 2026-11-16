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
        'rows' => 'integer', 'seats_per_row' => 'integer', 'sort' => 'integer',
    ];

    public function seatMap(): BelongsTo
    {
        return $this->belongsTo(SeatMap::class);
    }

    public function capacity(): int
    {
        return $this->rows * $this->seats_per_row;
    }
}
