<?php

namespace App\Services\Auth;

use App\Services\Benefits\SmsSender;
use App\Services\OrderException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * 일회용 인증번호 (가입·새 기기 로그인·비밀번호 재설정).
 *  - 휴대폰(문자) 또는 이메일. 중국 번호(+86)는 해외 발신 문자가 통신사에서 걸러지는 일이 잦아 이메일을 함께 연다.
 *  - 6자리, 10분, 5번 틀리면 폐기, 번호·IP별 발송 한도. 코드는 HMAC 해시만 저장한다.
 *  - 개발 모드(문자: siren.sms_driver=log, 메일: log/array 메일러)에서는 화면에 보여 줄 수 있게 코드를 돌려준다.
 */
class OneTimeCodeService
{
    private const TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    private const MAX_PER_DESTINATION_HOUR = 5;

    private const MAX_PER_IP_HOUR = 20;

    /** @return string|null 개발 모드면 코드 */
    public function send(string $purpose, string $channel, string $destination, ?string $ip): ?string
    {
        $recent = DB::table('one_time_codes')->where('created_at', '>', now()->subHour());
        if ((clone $recent)->where('destination', $destination)->count() >= self::MAX_PER_DESTINATION_HOUR
            || ($ip && (clone $recent)->where('ip', $ip)->count() >= self::MAX_PER_IP_HOUR)) {
            throw new OrderException('인증번호를 너무 많이 요청했어요. 1시간 뒤에 다시 해 주세요.', 'too_many', 429);
        }

        $code = (string) random_int(100000, 999999);
        DB::table('one_time_codes')->insert([
            'purpose' => $purpose, 'channel' => $channel, 'destination' => $destination,
            'code_hash' => $this->hash($purpose, $destination, $code), 'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'ip' => $ip, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // 받는 사람이 고른 화면 언어로 보낸다
        $text = __('[:brand] 인증번호 :code (10분 안에 입력). 누구에게도 알려 주지 마세요.', ['code' => $code, 'brand' => \App\Models\Setting::brand()]);
        if ($channel === 'email') {
            Mail::raw($text, fn ($m) => $m->to($destination)->subject(__('[:brand] 인증번호 :code', ['code' => $code, 'brand' => \App\Models\Setting::brand()])));

            return in_array(config('mail.default'), ['log', 'array'], true) && config('siren.show_dev_codes') ? $code : null;
        }
        if (config('siren.sms_driver') === 'log') {
            Log::info("[SMS 개발용] {$destination} {$text}");

            // 화면에는 개발용 표시를 켠 서버에서만 보여 준다 (그 밖에는 로그에만 남는다)
            return config('siren.show_dev_codes') ? $code : null;
        }
        app(SmsSender::class)->send($destination, $text);

        return null;
    }

    /** 맞으면 소진 처리하고 true. 틀리면 OrderException. */
    public function verify(string $purpose, string $destination, string $code): bool
    {
        $row = DB::table('one_time_codes')->where('purpose', $purpose)->where('destination', $destination)
            ->whereNull('consumed_at')->where('expires_at', '>', now())->orderByDesc('id')->first();
        if (! $row) {
            throw new OrderException('인증번호가 만료됐어요. 다시 받아 주세요.', 'code_expired');
        }
        // 시도 횟수를 먼저 차감한다 (동시에 여러 번 보내도 5번을 넘기지 못하게, 조건부 UPDATE)
        if (! DB::table('one_time_codes')->where('id', $row->id)->where('attempts', '<', self::MAX_ATTEMPTS)->increment('attempts')) {
            throw new OrderException('여러 번 틀렸어요. 인증번호를 다시 받아 주세요.', 'too_many', 429);
        }
        if (! hash_equals($row->code_hash, $this->hash($purpose, $destination, preg_replace('/\D/', '', $code)))) {
            throw new OrderException('인증번호가 맞지 않아요.', 'code_wrong');
        }
        // 동시에 두 번 써도 한 번만 통과 (조건부 UPDATE)
        $consumed = DB::table('one_time_codes')->where('id', $row->id)->whereNull('consumed_at')->update(['consumed_at' => now(), 'updated_at' => now()]);
        if (! $consumed) {
            throw new OrderException('이미 쓴 인증번호예요. 다시 받아 주세요.', 'code_expired');
        }

        return true;
    }

    private function hash(string $purpose, string $destination, string $code): string
    {
        return hash_hmac('sha256', "{$purpose}|{$destination}|{$code}", (string) config('app.key'));
    }
}
