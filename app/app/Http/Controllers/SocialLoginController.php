<?php

namespace App\Http\Controllers;

use App\Models\MemberSocialAccount;
use App\Services\Auth\MemberAuthService;
use App\Services\Auth\SocialAuth;
use App\Services\OrderException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * SNS 로그인 (원본 다담다 구매자는 SNS 로만 로그인했다).
 *  - 연결된 SNS → 바로 로그인 (2단계 인증을 켠 회원은 그 단계를 거친다)
 *  - 처음 → 가입 화면으로 (이름·이메일 채움, 휴대폰 인증만 — 비밀번호 없이). 그 번호·이메일 회원이 이미 있으면 인증 후 그 계정에 연결
 *  - 로그인한 회원이 누르면 → 내 계정에 SNS 연결
 */
class SocialLoginController extends Controller
{
    public function __construct(private SocialAuth $social, private MemberAuthService $auth) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        abort_unless(SocialAuth::isEnabled($provider), 404);
        $state = Str::random(40);
        $request->session()->put("oauth_state.{$provider}", $state);

        return redirect()->away($this->social->authorizeUrl($provider, $state));
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(SocialAuth::isEnabled($provider), 404);
        $expected = $request->session()->pull("oauth_state.{$provider}");
        $label = SocialAuth::PROVIDERS[$provider];
        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect()->route('member.login')->withErrors(['identifier' => "{$label} 로그인을 다시 시작해 주세요."]);
        }
        if ($request->query('error') || ! $request->query('code')) {
            return redirect()->route('member.login')->withErrors(['identifier' => "{$label} 로그인을 취소했어요."]);
        }

        try {
            $u = $this->social->user($provider, (string) $request->query('code'), $expected);
        } catch (OrderException $e) {
            report($e);

            return redirect()->route('member.login')->withErrors(['identifier' => $e->getMessage()]);
        }

        $link = MemberSocialAccount::with('member')->where('provider', $provider)->where('provider_user_id', $u['id'])->first();
        $current = $request->user('member');

        // 로그인한 회원: 내 계정에 연결
        if ($current) {
            if ($link && $link->member_id !== $current->id) {
                return redirect()->route('member.account')->withErrors(['social' => "이 {$label} 계정은 다른 회원에 연결돼 있어요."]);
            }
            MemberSocialAccount::updateOrCreate(['member_id' => $current->id, 'provider' => $provider], ['provider_user_id' => $u['id'], 'email' => $u['email']]);

            return redirect()->route('member.account')->with('status', "{$label} 계정을 연결했어요. 다음부터 {$label}로 로그인할 수 있어요.");
        }

        // 연결된 SNS: 로그인
        if ($link?->member) {
            $member = $link->member;
            if ($member->isLocked()) {
                return redirect()->route('member.login')->withErrors(['identifier' => '계정이 잠시 잠겨 있어요. 잠시 뒤 다시 해 주세요.']);
            }
            $request->session()->put('member_pending', ['id' => $member->id, 'remember' => true, 'at' => now()->timestamp]);
            if ($member->hasTwoFactor()) {
                return redirect()->route('member.login.two-factor');
            }
            $this->auth->complete($request, $member);

            return redirect()->intended(route('home'));
        }

        // 처음: 가입(또는 기존 계정 연결)으로
        $request->session()->put('member_social', ['provider' => $provider, 'id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'at' => now()->timestamp]);

        return redirect()->route('member.signup')->with('status', "{$label} 계정 확인했어요. 휴대폰 인증만 하면 가입(또는 기존 계정 연결)이 끝나요.");
    }
}
