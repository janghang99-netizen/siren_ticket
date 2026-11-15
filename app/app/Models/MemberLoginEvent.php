<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 로그인 시도 기록 (성공·실패·잠김·추가 인증) */
class MemberLoginEvent extends Model
{
    public const UPDATED_AT = null;

    public const RESULTS = [
        'success' => '로그인', 'bad_password' => '비밀번호 틀림', 'locked' => '잠긴 계정 시도', 'step_up' => '새 기기 추가 인증 요청',
        'step_up_failed' => '추가 인증 실패', 'two_factor' => '2단계 인증 요청', 'two_factor_failed' => '2단계 인증 실패',
        'unknown' => '없는 계정 시도', 'throttled' => '시도 횟수 초과', 'password_changed' => '비밀번호 변경', 'logout_all' => '모든 기기 로그아웃',
    ];

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];

    public function label(): string
    {
        return self::RESULTS[$this->result] ?? $this->result;
    }
}
