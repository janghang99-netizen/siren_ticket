<?php

namespace App\Services\Benefits;

/** 문자 발송사 연동 자리 (미정). 정해지면 구현 클래스를 만들어 AppServiceProvider 에서 바인딩한다. */
interface SmsSender
{
    public function send(string $phone, string $message): void;
}
