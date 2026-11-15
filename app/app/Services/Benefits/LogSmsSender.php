<?php

namespace App\Services\Benefits;

use Illuminate\Support\Facades\Log;

/** 개발용 문자 발송 — 실제로 보내지 않고 로그(storage/logs/laravel.log)에만 남긴다. SIREN_SMS_DRIVER=log 일 때 쓴다. */
class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        Log::info("[SMS 개발용] {$phone} {$message}");
    }
}
