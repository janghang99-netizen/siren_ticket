<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Member;
use App\Services\Auth\MemberAuthService;
use App\Services\Auth\OneTimeCodeService;
use App\Services\Auth\Totp;
use App\Services\OrderException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * 팬 회원: 가입(휴대폰 또는 이메일 인증) · 로그인(새 기기 추가 인증 · 2단계 인증) · 계정 보안.
 * 해외 회원: 국가번호 휴대폰, 중국 등 문자가 잘 안 오는 나라는 이메일 인증. 중국 회원은 개인정보 국외 이전 동의(PIPL)를 따로 받는다.
 */
class MemberController extends Controller
{
    public function __construct(private MemberAuthService $auth, private OneTimeCodeService $codes) {}

    // ───────────────────────── 가입 ─────────────────────────

    public function signupForm(Request $request): View
    {
        return view('member.signup', ['pending' => $request->session()->get('member_signup')]);
    }

    public function signupStart(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'country' => ['required', 'in:'.implode(',', array_keys(Member::COUNTRIES))],
            // 거주 국가: 휴대폰 국가와 따로. 체인 기록·향후 코인 기능의 기준 (한국에 사는 중국인 = KR)
            'residence_country' => ['required', 'in:'.implode(',', array_keys(Member::COUNTRIES))],
            'phone' => ['required', 'string', 'max:25'],
            'email' => ['nullable', 'required_if:channel,email', 'email:rfc', 'max:190'],
            'channel' => ['required', 'in:sms,email'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
            'agree_terms' => ['accepted'],
            'agree_privacy' => ['accepted'],
            // 중국 번호 또는 중국 거주 회원만 필수 (중국 개인정보보호법 국외 이전 동의)
            'agree_overseas' => [($request->input('country') === 'CN' || $request->input('residence_country') === 'CN') ? 'accepted' : 'nullable'],
        ], [
            'email.required_if' => '이메일로 인증하려면 이메일을 적어 주세요.',
            'password.min' => '비밀번호는 10자 이상이어야 해요.',
            'password.letters' => '비밀번호에 영문자를 넣어 주세요.',
            'password.numbers' => '비밀번호에 숫자를 넣어 주세요.',
            'password.confirmed' => '비밀번호 확인이 달라요.',
            'agree_terms.accepted' => '이용약관에 동의해 주세요.',
            'agree_privacy.accepted' => '개인정보 수집·이용에 동의해 주세요.',
            'agree_overseas.accepted' => __('중국 회원은 개인정보 국외(한국) 이전에 동의해야 가입할 수 있어요.'),
        ]);

        $phone = Member::normalizePhone($data['country'], $data['phone']);
        if (! $phone) {
            return back()->withInput()->withErrors(['phone' => '이 나라의 휴대폰 번호 형식이 아니에요.']);
        }
        $email = ! empty($data['email']) ? mb_strtolower($data['email']) : null;
        if ($this->weakPassword($data['password'], $phone, $email)) {
            return back()->withInput()->withErrors(['password' => '너무 쉬운 비밀번호예요. 휴대폰 번호·이메일·흔한 단어가 들어가지 않게 해 주세요.']);
        }
        // 이미 가입한 번호·이메일: 안내는 하되 비밀번호 재설정으로 유도
        if (Member::where('phone', $phone)->exists() || ($email && Member::where('email', $email)->exists())) {
            return back()->withInput()->withErrors(['phone' => '이미 가입한 번호 또는 이메일이에요. 로그인하거나 비밀번호를 재설정해 주세요.']);
        }

        $dest = $data['channel'] === 'email' ? $email : $phone;
        try {
            $dev = $this->codes->send('signup', $data['channel'], $dest, $request->ip());
        } catch (OrderException $e) {
            return back()->withInput()->withErrors(['phone' => $e->getMessage()]);
        }

        $request->session()->put('member_signup', [
            'name' => $data['name'], 'country' => $data['country'], 'residence_country' => $data['residence_country'], 'phone' => $phone, 'email' => $email,
            'channel' => $data['channel'], 'destination' => $dest, 'password_hash' => Hash::make($data['password']),
            'locale' => app()->getLocale(),
            'at' => now()->timestamp,
        ]);

        return redirect()->route('member.signup')->with('dev_code', $dev)
            ->with('status', $data['channel'] === 'email' ? '이메일로 인증번호를 보냈어요. (스팸함도 확인해 주세요)' : '문자로 인증번호를 보냈어요.');
    }

    public function signupVerify(Request $request): RedirectResponse
    {
        $p = $request->session()->get('member_signup');
        if (! $p || $p['at'] < now()->subMinutes(30)->timestamp) {
            return redirect()->route('member.signup')->withErrors(['code' => '처음부터 다시 가입해 주세요.']);
        }
        $code = $request->validate(['code' => ['required', 'string', 'max:10']])['code'];
        try {
            $this->codes->verify('signup', $p['destination'], $code);
        } catch (OrderException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        $member = DB::transaction(function () use ($p) {
            if (Member::where('phone', $p['phone'])->exists() || ($p['email'] && Member::where('email', $p['email'])->exists())) {
                throw new OrderException('이미 가입한 번호 또는 이메일이에요.', 'duplicate', 409);
            }
            $m = new Member([
                'name' => $p['name'], 'country' => $p['country'], 'residence_country' => $p['residence_country'] ?? $p['country'],
                'phone' => $p['phone'], 'email' => $p['email'],
                'locale' => $p['locale'] ?? 'ko',   // 가입할 때 보던 화면 언어
                'phone_verified_at' => $p['channel'] === 'sms' ? now() : null,
                'email_verified_at' => $p['channel'] === 'email' ? now() : null,
                'customer_id' => Customer::forPhone($p['phone'])->id,   // 비회원 때 쌓인 마일리지·쿠폰이 그대로 이어진다
            ]);
            $m->forceFill(['password' => $p['password_hash'], 'password_changed_at' => now()])->save();   // 이미 해시된 값은 다시 해시하지 않는다

            return $m;
        });
        $request->session()->forget('member_signup');
        $request->session()->put('member_pending', ['id' => $member->id, 'remember' => false, 'at' => now()->timestamp]);
        $this->auth->complete($request, $member, trustDevice: true);

        return redirect()->intended(route('member.account'))->with('status', "{$member->name}님, 가입을 환영해요.");
    }

    // ───────────────────────── 로그인 ─────────────────────────

    public function loginForm(Request $request): View
    {
        $return = (string) $request->query('return');
        if (str_starts_with($return, '/') && ! str_starts_with($return, '//')) {
            $request->session()->put('url.intended', $return);
        }

        return view('member.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:190'], 'password' => ['required', 'string', 'max:200']],
            ['identifier.required' => '휴대폰 번호나 이메일을 적어 주세요.']);
        try {
            $r = $this->auth->attempt($request, $data['identifier'], $data['password'], $request->boolean('remember'));
        } catch (OrderException $e) {
            return back()->withInput($request->only('identifier'))->withErrors(['identifier' => $e->getMessage()]);
        }

        return match ($r['next']) {
            'two_factor' => redirect()->route('member.login.two-factor'),
            'step_up' => redirect()->route('member.login.step-up')->with('dev_code', $r['dev_code'] ?? null),
            default => redirect()->intended(route('member.account')),
        };
    }

    public function stepUpForm(Request $request): View|RedirectResponse
    {
        $member = $this->auth->pending($request);
        if (! $member) {
            return redirect()->route('member.login');
        }
        [$channel] = $member->stepUpChannel();

        return view('member.step-up', ['channel' => $channel, 'to' => $channel === 'sms' ? $member->maskedPhone() : $member->maskedEmail()]);
    }

    public function stepUp(Request $request): RedirectResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:10']])['code'];
        try {
            $this->auth->completeStepUp($request, $code);
        } catch (OrderException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()->intended(route('member.account'))->with('status', '이 기기를 30일 동안 기억할게요.');
    }

    public function twoFactorForm(Request $request): View|RedirectResponse
    {
        return $this->auth->pending($request) ? view('member.two-factor') : redirect()->route('member.login');
    }

    public function twoFactor(Request $request): RedirectResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:20']])['code'];
        try {
            $this->auth->completeTwoFactor($request, $code);
        } catch (OrderException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()->intended(route('member.account'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('member')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    // ───────────────────────── 비밀번호 재설정 ─────────────────────────

    public function forgotForm(Request $request): View
    {
        return view('member.forgot', ['pending' => $request->session()->get('member_reset')]);
    }

    public function forgotSend(Request $request): RedirectResponse
    {
        $identifier = $request->validate(['identifier' => ['required', 'string', 'max:190']])['identifier'];
        $member = Member::findByIdentifier($identifier);
        $dev = null;
        if ($member) {
            [$channel, $dest] = str_contains($identifier, '@') && $member->email_verified_at ? ['email', $member->email] : $member->stepUpChannel();
            try {
                $dev = $this->codes->send('reset', $channel, $dest, $request->ip());
            } catch (OrderException $e) {
                return back()->withErrors(['identifier' => $e->getMessage()]);
            }
            $request->session()->put('member_reset', ['id' => $member->id, 'destination' => $dest, 'at' => now()->timestamp]);
        } else {
            // 없는 계정이어도 같은 화면 (가입 여부를 알려 주지 않는다)
            $request->session()->put('member_reset', ['id' => 0, 'destination' => '', 'at' => now()->timestamp]);
        }

        return redirect()->route('member.password.forgot')->with('dev_code', $dev)->with('status', '가입한 계정이면 인증번호를 보냈어요.');
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $p = $request->session()->get('member_reset');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ], ['password.min' => '비밀번호는 10자 이상이어야 해요.', 'password.confirmed' => '비밀번호 확인이 달라요.']);
        $member = $p ? Member::find($p['id']) : null;
        if (! $member || $p['at'] < now()->subMinutes(15)->timestamp) {
            return back()->withErrors(['code' => '인증번호가 맞지 않거나 만료됐어요.']);
        }
        try {
            $this->codes->verify('reset', $p['destination'], $data['code']);
        } catch (OrderException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }
        if ($this->weakPassword($data['password'], $member->phone, $member->email)) {
            return back()->withErrors(['password' => '너무 쉬운 비밀번호예요.']);
        }
        $this->auth->changePassword($request, $member, $data['password']);
        $request->session()->forget('member_reset');

        return redirect()->route('member.login')->with('status', '비밀번호를 바꿨어요. 다른 기기의 로그인은 모두 끊었어요.');
    }

    // ───────────────────────── 내 계정 · 보안 ─────────────────────────

    public function account(Request $request): View
    {
        $member = $request->user('member');

        return view('member.account', [
            'member' => $member,
            'devices' => $member->devices()->where('trusted_until', '>', now())->latest('last_seen_at')->get(),
            'events' => $member->loginEvents()->latest('id')->limit(15)->get(),
            'currentDevice' => $this->auth->trustedDevice($request, $member)?->id,
            'setupSecret' => $request->session()->get('member_2fa_setup'),
            'recoveryCodes' => session('recovery_codes'),
        ]);
    }

    public function changePassword(Request $request): RedirectResponse
    {
        $member = $request->user('member');
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ], ['password.min' => '비밀번호는 10자 이상이어야 해요.', 'password.confirmed' => '비밀번호 확인이 달라요.']);
        if (! Hash::check($data['current_password'], $member->password)) {
            return back()->withErrors(['current_password' => '지금 비밀번호가 맞지 않아요.']);
        }
        if ($this->weakPassword($data['password'], $member->phone, $member->email)) {
            return back()->withErrors(['password' => '너무 쉬운 비밀번호예요.']);
        }
        $this->auth->changePassword($request, $member, $data['password']);

        return back()->with('status', '비밀번호를 바꿨어요. 다른 기기의 로그인은 모두 끊었어요.');
    }

    /** 거주 국가 변경 (이사·유학 등). 비밀번호 재확인. 코인 단계에서는 본인 확인 서류와 함께 바꾸게 한다. */
    public function updateResidence(Request $request): RedirectResponse
    {
        $member = $request->user('member');
        $data = $request->validate([
            'residence_country' => ['required', 'in:'.implode(',', array_keys(Member::COUNTRIES))],
            'password' => ['required', 'string'],
            'agree_overseas' => [$request->input('residence_country') === 'CN' ? 'accepted' : 'nullable'],
        ], ['agree_overseas.accepted' => __('중국 거주로 바꾸려면 개인정보 국외 이전에 동의해 주세요.')]);
        if (! Hash::check($data['password'], $member->password)) {
            return back()->withErrors(['residence' => '비밀번호가 맞지 않아요.']);
        }
        $member->forceFill(['residence_country' => $data['residence_country']])->save();

        return back()->with('status', '거주 국가를 바꿨어요. 이후 예매하는 티켓부터 적용돼요.');
    }

    public function twoFactorSetup(Request $request): RedirectResponse
    {
        $request->session()->put('member_2fa_setup', Totp::newSecret());

        return back();
    }

    public function twoFactorConfirm(Request $request): RedirectResponse
    {
        $member = $request->user('member');
        $secret = $request->session()->get('member_2fa_setup');
        $code = $request->validate(['code' => ['required', 'string', 'max:10']])['code'];
        if (! $secret || Totp::verify($secret, $code) === null) {
            return back()->withErrors(['code' => 'OTP 앱의 6자리 코드가 맞지 않아요. 휴대폰 시간이 맞는지도 확인해 주세요.']);
        }
        $recovery = collect(range(1, 8))->map(fn () => strtoupper(Str::random(4).'-'.Str::random(4)))->all();
        $member->forceFill([
            'two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', str_replace('-', '', $c)), $recovery),
        ])->save();
        $request->session()->forget('member_2fa_setup');

        return back()->with('recovery_codes', $recovery)->with('status', '2단계 인증을 켰어요. 복구 코드를 안전한 곳에 적어 두세요.');
    }

    public function twoFactorDisable(Request $request): RedirectResponse
    {
        $member = $request->user('member');
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:20']]);
        if (! Hash::check($data['password'], $member->password) || ! $this->auth->checkTwoFactor($member, $data['code'])) {
            return back()->withErrors(['disable' => '비밀번호나 인증 코드가 맞지 않아요.']);
        }
        $member->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

        return back()->with('status', '2단계 인증을 껐어요.');
    }

    public function revokeDevice(Request $request, int $device): RedirectResponse
    {
        $request->user('member')->devices()->whereKey($device)->delete();

        return back()->with('status', '그 기기는 다음 로그인 때 다시 인증해야 해요.');
    }

    public function logoutEverywhere(Request $request): RedirectResponse
    {
        $this->auth->logoutEverywhere($request, $request->user('member'));

        return $this->logout($request)->with('status', '모든 기기에서 로그아웃했어요.');
    }

    /** 쉬운 비밀번호 거르기: 번호·이메일 포함, 흔한 비밀번호, 같은 문자 반복 */
    private function weakPassword(string $password, string $phone, ?string $email): bool
    {
        $lower = mb_strtolower($password);
        $common = ['password1', 'qwerty1234', '1q2w3e4r', 'asdf1234', 'abcd1234', 'iloveyou1', 'woaini1314', 'a123456789', 'qwer1234!', 'admin1234'];
        $local = $email ? explode('@', $email)[0] : null;

        return in_array($lower, $common, true)
            || str_contains(preg_replace('/\D/', '', $password), substr($phone, -8))
            || ($local && strlen($local) >= 4 && str_contains($lower, mb_strtolower($local)))
            || (bool) preg_match('/^(.)\1+$/', $password)
            || count(array_unique(str_split($password))) < 5;
    }
}
