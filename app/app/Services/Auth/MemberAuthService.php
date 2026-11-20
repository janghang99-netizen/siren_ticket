<?php

namespace App\Services\Auth;

use App\Models\Member;
use App\Models\MemberDevice;
use App\Models\MemberLoginEvent;
use App\Services\OrderException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * 팬 회원 로그인 (해킹 대비).
 *  1. 시도 제한: IP당 분당 20회, 계정당 분당 5회 (자격증명 대입 공격·무차별 대입 차단)
 *  2. 계정 잠금: 5번 연속 틀리면 15분 → 10번 1시간 → 15번 이상 24시간
 *  3. 계정 존재 여부를 드러내지 않는다: 없는 계정·틀린 비밀번호 모두 같은 문구, 같은 해시 비용
 *  4. 처음 보는 기기면 인증번호를 한 번 더 받는다 (비밀번호가 새도 계정을 못 가져간다). 인증한 기기는 30일 신뢰
 *  5. 선택 2단계 인증(OTP 앱) + 복구 코드
 *  6. 로그인하면 세션 ID를 새로 만들고(세션 고정 공격 방지), 비밀번호를 바꾸면 다른 기기 세션을 모두 끊는다
 *  7. 모든 시도를 기록 (회원은 내 로그인 기록에서 확인)
 */
class MemberAuthService
{
    public const DEVICE_COOKIE = 'siren_device';

    private const TRUST_DAYS = 30;

    public function __construct(private OneTimeCodeService $codes) {}

    /**
     * 아이디(이메일·휴대폰)와 비밀번호 확인. 통과하면 다음 단계를 돌려준다.
     *
     * @return array{member: Member, next: string, dev_code?: ?string}  next: done / step_up / two_factor
     */
    public function attempt(Request $request, string $identifier, string $password, bool $remember): array
    {
        $idHash = hash('sha256', mb_strtolower(trim($identifier)));
        $ipKey = 'member-login:ip:'.$request->ip();
        $idKey = 'member-login:id:'.$idHash;
        if (RateLimiter::tooManyAttempts($ipKey, 20) || RateLimiter::tooManyAttempts($idKey, 5)) {
            $this->log(null, $idHash, 'throttled', $request);
            $wait = max(RateLimiter::availableIn($ipKey), RateLimiter::availableIn($idKey));
            throw new OrderException(__('로그인 시도가 너무 많아요. :s초 뒤에 다시 해 주세요.', ['s' => $wait]), 'throttled', 429);
        }
        RateLimiter::hit($ipKey, 60);
        RateLimiter::hit($idKey, 60);

        $member = Member::findByIdentifier($identifier);
        if (! $member) {
            Hash::check($password, '$2y$12$'.str_repeat('a', 53));   // 없는 계정도 같은 시간이 걸리게
            $this->log(null, $idHash, 'unknown', $request);
            throw new OrderException('아이디 또는 비밀번호가 맞지 않아요.', 'bad_credentials');
        }
        if ($member->isLocked()) {
            $this->log($member, $idHash, 'locked', $request);
            throw new OrderException(__('여러 번 틀려서 잠시 잠겼어요. :time 뒤에 다시 하거나 비밀번호를 재설정해 주세요.',
                ['time' => $member->locked_until->locale(app()->getLocale())->diffForHumans(now(), true)]), 'locked', 423);
        }
        if (! Hash::check($password, $member->password)) {
            $fails = $member->failed_logins + 1;
            $lock = match (true) {
                $fails >= 15 => now()->addDay(),
                $fails >= 10 => now()->addHour(),
                $fails >= 5 => now()->addMinutes(15),
                default => null,
            };
            $member->forceFill(['failed_logins' => $fails, 'locked_until' => $lock])->save();
            $this->log($member, $idHash, 'bad_password', $request);
            throw new OrderException('아이디 또는 비밀번호가 맞지 않아요.', 'bad_credentials');
        }

        RateLimiter::clear($idKey);
        $member->forceFill(['failed_logins' => 0, 'locked_until' => null])->save();
        if (Hash::needsRehash($member->password)) {
            $member->forceFill(['password' => $password])->save();
        }

        $request->session()->put('member_pending', ['id' => $member->id, 'remember' => $remember, 'at' => now()->timestamp]);

        if ($member->hasTwoFactor()) {
            $this->log($member, $idHash, 'two_factor', $request);

            return ['member' => $member, 'next' => 'two_factor'];
        }
        if (! $this->trustedDevice($request, $member)) {
            [$channel, $dest] = $member->stepUpChannel();
            $dev = $this->codes->send('login', $channel, $dest, $request->ip());
            $this->log($member, $idHash, 'step_up', $request);

            return ['member' => $member, 'next' => 'step_up', 'dev_code' => $dev];
        }

        $this->complete($request, $member);

        return ['member' => $member, 'next' => 'done'];
    }

    /** 비밀번호를 통과한 뒤 기다리는 회원 (5분 안) */
    public function pending(Request $request): ?Member
    {
        $p = $request->session()->get('member_pending');
        if (! $p || $p['at'] < now()->subMinutes(5)->timestamp) {
            return null;
        }

        return Member::find($p['id']);
    }

    /** 새 기기 인증번호 확인 → 로그인 + 이 기기 신뢰 */
    public function completeStepUp(Request $request, string $code): Member
    {
        $member = $this->pending($request) ?? throw new OrderException('처음부터 다시 로그인해 주세요.', 'expired');
        // OTP 를 켠 회원은 OTP 로만 통과한다 (인증번호 단계로 OTP 를 건너뛰지 못하게)
        if ($member->hasTwoFactor()) {
            throw new OrderException('처음부터 다시 로그인해 주세요.', 'expired');
        }
        [, $dest] = $member->stepUpChannel();
        try {
            $this->codes->verify('login', $dest, $code);
        } catch (OrderException $e) {
            $this->log($member, null, 'step_up_failed', $request);
            throw $e;
        }
        $this->complete($request, $member, trustDevice: true);

        return $member;
    }

    /** OTP 앱 코드 또는 복구 코드 확인 → 로그인 */
    public function completeTwoFactor(Request $request, string $code): Member
    {
        $member = $this->pending($request) ?? throw new OrderException('처음부터 다시 로그인해 주세요.', 'expired');
        // OTP 를 켜지 않은 회원은 이 단계로 들어올 수 없다 (새 기기 인증번호 단계를 건너뛰지 못하게)
        if (! $member->hasTwoFactor()) {
            throw new OrderException('처음부터 다시 로그인해 주세요.', 'expired');
        }
        $rlKey = 'member-2fa:'.$member->id;
        if (RateLimiter::tooManyAttempts($rlKey, 5)) {
            throw new OrderException('여러 번 틀렸어요. 잠시 뒤에 다시 해 주세요.', 'throttled', 429);
        }
        RateLimiter::hit($rlKey, 300);

        if (! $this->checkTwoFactor($member, $code)) {
            $this->log($member, null, 'two_factor_failed', $request);
            throw new OrderException('인증 코드가 맞지 않아요.', 'code_wrong');
        }
        RateLimiter::clear($rlKey);
        $this->complete($request, $member, trustDevice: true);

        return $member;
    }

    /** OTP 앱 코드(리플레이 방지) 또는 1회용 복구 코드 */
    public function checkTwoFactor(Member $member, string $code): bool
    {
        if (! $member->hasTwoFactor()) {
            return false;
        }
        $lastKey = 'member-totp-step:'.$member->id;
        $step = Totp::verify((string) $member->two_factor_secret, $code, Cache::get($lastKey));
        if ($step !== null) {
            Cache::put($lastKey, $step, 120);

            return true;
        }
        $codes = $member->two_factor_recovery_codes ?? [];
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        foreach ($codes as $i => $hash) {
            if (hash_equals($hash, hash('sha256', $normalized))) {
                unset($codes[$i]);
                $member->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /** 로그인 확정: 세션 ID 재발급, 세션 버전 기록, 기기 신뢰 쿠키 */
    public function complete(Request $request, Member $member, bool $trustDevice = false): void
    {
        $remember = (bool) ($request->session()->get('member_pending.remember') ?? false);
        $request->session()->forget('member_pending');
        Auth::guard('member')->login($member, $remember);
        $request->session()->regenerate();
        $request->session()->put('member_session_version', $member->session_version);

        $member->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        if ($trustDevice) {
            $this->trust($request, $member);
        } else {
            $this->trustedDevice($request, $member)?->update(['last_seen_at' => now(), 'ip' => $request->ip()]);
        }
        $this->log($member, null, 'success', $request);
    }

    public function trust(Request $request, Member $member): void
    {
        $token = Str::random(48);
        MemberDevice::create([
            'member_id' => $member->id, 'token_hash' => hash('sha256', $token), 'label' => self::deviceLabel((string) $request->userAgent()),
            'ip' => $request->ip(), 'trusted_until' => now()->addDays(self::TRUST_DAYS), 'last_seen_at' => now(),
        ]);
        Cookie::queue(Cookie::make(self::DEVICE_COOKIE.'_'.$member->id, $token, self::TRUST_DAYS * 24 * 60, null, null, $request->isSecure(), true, false, 'lax'));
    }

    public function trustedDevice(Request $request, Member $member): ?MemberDevice
    {
        $token = $request->cookie(self::DEVICE_COOKIE.'_'.$member->id);

        return $token ? MemberDevice::where('member_id', $member->id)->where('token_hash', hash('sha256', $token))
            ->where('trusted_until', '>', now())->first() : null;
    }

    /** 비밀번호 변경: 세션 버전을 올려 다른 기기 로그인을 끊고, 신뢰 기기·자동 로그인도 지운다 */
    public function changePassword(Request $request, Member $member, string $newPassword): void
    {
        $member->forceFill([
            'password' => $newPassword, 'password_changed_at' => now(), 'session_version' => $member->session_version + 1,
            'remember_token' => Str::random(60), 'failed_logins' => 0, 'locked_until' => null,
        ])->save();
        $member->devices()->delete();
        $this->log($member, null, 'password_changed', $request);
        if (Auth::guard('member')->id() === $member->id) {
            $request->session()->put('member_session_version', $member->session_version);
            $this->trust($request, $member);   // 지금 기기는 유지
        }
    }

    public function logoutEverywhere(Request $request, Member $member): void
    {
        $member->forceFill(['session_version' => $member->session_version + 1, 'remember_token' => Str::random(60)])->save();
        $member->devices()->delete();
        $this->log($member, null, 'logout_all', $request);
    }

    public function log(?Member $member, ?string $idHash, string $result, Request $request): void
    {
        MemberLoginEvent::create([
            'member_id' => $member?->id, 'identifier_hash' => $idHash, 'result' => $result,
            'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 200),
        ]);
    }

    public static function deviceLabel(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'MicroMessenger') => 'WeChat',
            str_contains($ua, 'KAKAOTALK') => '카카오톡',
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            str_contains($ua, 'Firefox/') => 'Firefox',
            default => '브라우저',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'macOS',
            default => '',
        };

        return trim("{$browser} {$os}");
    }
}
