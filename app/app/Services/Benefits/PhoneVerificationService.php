<?php

namespace App\Services\Benefits;

use App\Models\Customer;
use App\Services\OrderException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 휴대폰 인증 (마일리지·쿠폰을 쓰기 전에 한 번).
 * 번호만 알면 남의 혜택을 쓸 수 있는 문제(원본 스탬프 번호 합치기 결함)를 막는다.
 * 문자 발송사는 미정 — config('siren.sms_driver') 가 log 면 로그에만 남기고 화면에 개발용 번호를 보여 준다.
 */
class PhoneVerificationService
{
    private const TTL_MINUTES = 5;

    private const MAX_ATTEMPTS = 5;

    private const MAX_SENDS_PER_HOUR = 5;

    /** @return string|null 개발 모드면 인증번호, 실발송이면 null */
    public function send(string $phone): ?string
    {
        $phone = Customer::normalize($phone);
        if (! preg_match('/^01\d{8,9}$/', $phone)) {
            throw new OrderException('휴대폰 번호를 010-1234-5678 형식으로 적어 주세요.', 'phone_invalid');
        }
        if (DB::table('phone_verifications')->where('phone', $phone)->where('created_at', '>', now()->subHour())->count() >= self::MAX_SENDS_PER_HOUR) {
            throw new OrderException('인증번호를 너무 많이 요청했어요. 1시간 뒤에 다시 해 주세요.', 'too_many', 429);
        }

        $code = (string) random_int(100000, 999999);
        DB::table('phone_verifications')->insert([
            'phone' => $phone, 'code_hash' => hash('sha256', $phone.$code), 'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if (config('siren.sms_driver') === 'log') {
            Log::info("[SMS 개발용] {$phone} 인증번호 {$code}");

            // 화면에는 개발용 표시를 켠 서버에서만 보여 준다 (그 밖에는 로그에만 남는다)
            return config('siren.show_dev_codes') ? $code : null;
        }

        // 실발송: 문자 발송사가 정해지면 SmsSender 구현을 바인딩한다
        app(SmsSender::class)->send($phone, '['.\App\Models\Setting::brand()."] 인증번호 {$code} (5분 안에 입력)");

        return null;
    }

    /** 맞으면 고객(없으면 생성)을 돌려준다. */
    public function verify(string $phone, string $code): Customer
    {
        $phone = Customer::normalize($phone);
        $row = DB::table('phone_verifications')->where('phone', $phone)->whereNull('verified_at')
            ->where('expires_at', '>', now())->orderByDesc('id')->first();
        if (! $row) {
            throw new OrderException('인증번호가 만료됐어요. 다시 받아 주세요.', 'code_expired');
        }
        // 시도 횟수를 먼저 차감한다 (동시에 여러 번 보내도 5번을 넘기지 못하게, 조건부 UPDATE)
        if (! DB::table('phone_verifications')->where('id', $row->id)->where('attempts', '<', self::MAX_ATTEMPTS)->increment('attempts')) {
            throw new OrderException('여러 번 틀렸어요. 인증번호를 다시 받아 주세요.', 'too_many', 429);
        }
        if (! hash_equals($row->code_hash, hash('sha256', $phone.trim($code)))) {
            throw new OrderException('인증번호가 맞지 않아요.', 'code_wrong');
        }
        DB::table('phone_verifications')->where('id', $row->id)->update(['verified_at' => now(), 'updated_at' => now()]);

        $customer = Customer::forPhone($phone);
        $customer->update(['phone_verified_at' => now()]);

        return $customer;
    }
}
