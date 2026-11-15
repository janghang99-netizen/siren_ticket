<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 구글 번역 오역 교정 (원본 다담다 FIX_DICT) — 화면의 [잘못된 번역] 을 [올바른 번역] 으로 바꾼다 */
class TranslationFix extends Model
{
    protected $fillable = ['lang', 'wrong', 'right', 'user_id'];
}
