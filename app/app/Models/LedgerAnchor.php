<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 원장 구간의 머클 루트 기록. network=local 이면 아직 체인에 올리지 않은 상태. */
class LedgerAnchor extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['anchored_at' => 'datetime', 'from_id' => 'integer', 'to_id' => 'integer', 'row_count' => 'integer'];
}
