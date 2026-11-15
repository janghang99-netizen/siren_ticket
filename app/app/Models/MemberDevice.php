<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 회원이 추가 인증을 마친 기기 (쿠키 토큰의 해시만 저장) */
class MemberDevice extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['trusted_until' => 'datetime', 'last_seen_at' => 'datetime'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
