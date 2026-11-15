<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Member;
use App\Models\MemberLoginEvent;
use App\Services\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 팬 회원 가입·로그인 보안 (티켓 예매 전용 계정, 해외·중국 회원 포함) */
class MemberAuthTest extends TestCase
{
    use RefreshDatabase;

    private function signup(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('member.signup.start'), $over + [
            'name' => '김팬', 'country' => 'KR', 'residence_country' => $over['country'] ?? 'KR', 'phone' => '010-2222-3333', 'channel' => 'sms',
            'password' => 'blue-ocean-77', 'password_confirmation' => 'blue-ocean-77', 'agree_terms' => 1, 'agree_privacy' => 1,
        ]);
    }

    public function test_korean_signup_verifies_phone_and_links_existing_benefits(): void
    {
        $customer = Customer::forPhone('01022223333');   // 비회원 때 쌓은 혜택
        $this->signup()->assertRedirect(route('member.signup'));
        $this->post(route('member.signup.verify'), ['code' => session('dev_code')])->assertRedirect(route('member.account'));

        $m = Member::first();
        $this->assertSame('01022223333', $m->phone);
        $this->assertNotNull($m->phone_verified_at);
        $this->assertSame($customer->id, $m->customer_id);
        $this->assertNotSame('blue-ocean-77', $m->password);   // 해시 저장
        $this->assertAuthenticatedAs($m, 'member');
    }

    public function test_chinese_member_signs_up_with_email_and_overseas_consent(): void
    {
        $base = ['name' => '李华', 'country' => 'CN', 'phone' => '+86 138 0013 8000', 'channel' => 'email', 'email' => 'lihua@qq.com'];
        $this->signup($base)->assertSessionHasErrors('agree_overseas');   // 개인정보 국외 이전 동의 필수 (PIPL)

        $this->signup($base + ['agree_overseas' => 1])->assertSessionHasNoErrors();
        $this->post(route('member.signup.verify'), ['code' => session('dev_code')]);

        $m = Member::first();
        $this->assertSame('8613800138000', $m->phone);
        $this->assertNotNull($m->email_verified_at);
        $this->assertNull($m->phone_verified_at);
        $this->assertSame(['email', 'lihua@qq.com'], $m->stepUpChannel());   // 새 기기 인증도 이메일로
        $this->assertSame('CN', $m->residence_country);
    }

    public function test_screens_are_korean_source_translated_by_google_widget(): void
    {
        // 서버는 한국어 원문 한 벌만 — 다른 언어는 구글 번역 위젯이 화면에서 바꾼다 (원본 다담다 방식)
        $this->withHeader('Accept-Language', 'zh-CN,zh;q=0.9')->get(route('member.login'))
            ->assertOk()->assertSee('lang="ko"', false)->assertDontSee('会员登录')
            ->assertSee('translate.google.com/translate_a/element.js', false)
            ->assertSee('data-lang="zh-CN"', false)->assertSee('translation-links', false);
    }

    public function test_phone_normalization_rejects_mismatched_country(): void
    {
        $this->assertSame('01012345678', Member::normalizePhone('KR', '+82 10-1234-5678'));
        $this->assertSame('8613800138000', Member::normalizePhone('CN', '13800138000'));
        $this->assertNull(Member::normalizePhone('CN', '+82 10-1234-5678'));
        $this->assertNull(Member::normalizePhone('CN', '12345'));
    }

    public function test_weak_or_personal_passwords_are_rejected(): void
    {
        $this->signup(['password' => 'short1', 'password_confirmation' => 'short1'])->assertSessionHasErrors('password');
        $this->signup(['password' => 'pw22223333a', 'password_confirmation' => 'pw22223333a'])->assertSessionHasErrors('password');   // 번호 포함
    }

    public function test_new_device_needs_code_then_device_is_trusted(): void
    {
        $member = $this->makeMember('01055556666', 'river-stone-42');
        $this->post(route('member.login.attempt'), ['identifier' => '010-5555-6666', 'password' => 'river-stone-42'])
            ->assertRedirect(route('member.login.step-up'));
        $this->assertGuest('member');

        $res = $this->post(route('member.login.step-up'), ['code' => session('dev_code')]);
        $res->assertRedirect(route('member.account'));
        $this->assertAuthenticatedAs($member, 'member');
        $token = $res->getCookie('siren_device_'.$member->id, false)?->getValue();
        $this->assertNotNull($token);

        // 같은 기기(쿠키)로 다시 로그인하면 추가 인증 없이 바로
        $this->post(route('member.logout'));
        $this->withUnencryptedCookie('siren_device_'.$member->id, $token)
            ->post(route('member.login.attempt'), ['identifier' => '01055556666', 'password' => 'river-stone-42'])
            ->assertRedirect(route('member.account'));
        $this->assertAuthenticatedAs($member, 'member');
    }

    public function test_repeated_failures_lock_account_and_unknown_user_gets_same_message(): void
    {
        $member = $this->makeMember('01077778888', 'right-pass-11');
        foreach (range(1, 5) as $i) {
            $this->post(route('member.login.attempt'), ['identifier' => '01077778888', 'password' => 'wrong-'.$i]);
        }
        $this->assertTrue($member->fresh()->isLocked());

        // 맞는 비밀번호여도 잠긴 동안은 못 들어온다
        $this->travel(61)->seconds();
        $this->post(route('member.login.attempt'), ['identifier' => '01077778888', 'password' => 'right-pass-11'])
            ->assertSessionHasErrors('identifier');
        $this->assertGuest('member');

        $this->post(route('member.login.attempt'), ['identifier' => '01000000000', 'password' => 'whatever-1']);
        $this->assertSame('아이디 또는 비밀번호가 맞지 않아요.', session('errors')->first('identifier'));
        $this->assertGreaterThan(0, MemberLoginEvent::where('result', 'unknown')->count());
    }

    public function test_totp_matches_rfc6238_vector_and_blocks_replay(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';   // "12345678901234567890"
        $this->assertSame('287082', Totp::code($secret, intdiv(59, 30)));
        $step = Totp::verify($secret, '287082', null, 59);
        $this->assertSame(1, $step);
        $this->assertNull(Totp::verify($secret, '287082', $step, 59));   // 같은 코드 다시 못 씀
    }

    public function test_two_factor_login_and_recovery_code(): void
    {
        $member = $this->makeMember('01033334444', 'moon-light-55');
        $secret = Totp::newSecret();
        $member->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [hash('sha256', 'ABCD1234')]])->save();

        $this->post(route('member.login.attempt'), ['identifier' => '01033334444', 'password' => 'moon-light-55'])
            ->assertRedirect(route('member.login.two-factor'));
        $this->post(route('member.login.two-factor'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post(route('member.login.two-factor'), ['code' => Totp::code($secret, intdiv(time(), 30))])->assertRedirect();
        $this->assertAuthenticatedAs($member, 'member');

        $this->post(route('member.logout'));
        $this->post(route('member.login.attempt'), ['identifier' => '01033334444', 'password' => 'moon-light-55']);
        $this->post(route('member.login.two-factor'), ['code' => 'abcd-1234'])->assertRedirect();
        $this->assertAuthenticatedAs($member, 'member');
        $this->assertSame([], $member->fresh()->two_factor_recovery_codes);   // 복구 코드는 한 번만
    }

    public function test_password_change_elsewhere_logs_out_this_session(): void
    {
        $member = $this->actingAsMember($this->makeMember('01099990000', 'old-secret-88'));
        $this->get(route('member.account'))->assertOk();

        $member->forceFill(['session_version' => $member->session_version + 1])->save();   // 다른 기기에서 비밀번호를 바꿈
        $this->get(route('member.account'))->assertRedirect(route('member.login'));
        $this->assertGuest('member');
    }

    public function test_reset_password_does_not_reveal_accounts(): void
    {
        $this->post(route('member.password.send'), ['identifier' => '01011112222'])->assertSessionHas('status', '가입한 계정이면 인증번호를 보냈어요.');
        $this->assertSame(0, DB::table('one_time_codes')->count());

        $member = $this->makeMember('01011112222', 'first-pass-12');
        $this->post(route('member.password.send'), ['identifier' => '01011112222']);
        $this->post(route('member.password.reset'), ['code' => session('dev_code'), 'password' => 'second-pass-34', 'password_confirmation' => 'second-pass-34'])
            ->assertRedirect(route('member.login'));
        $this->assertSame(2, $member->fresh()->session_version);
    }
}
