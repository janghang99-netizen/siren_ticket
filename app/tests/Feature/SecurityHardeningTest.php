<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Member;
use App\Services\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 보안 점검에서 나온 문제들이 다시 생기지 않게 지키는 테스트 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_otp_step_cannot_replace_new_device_code(): void
    {
        // OTP 를 켜지 않은 회원: 비밀번호만 알고 OTP 주소로 직접 들어와도 통과하지 못한다
        $this->makeMember('01055556666', 'river-stone-42');
        $this->post(route('member.login.attempt'), ['identifier' => '01055556666', 'password' => 'river-stone-42'])
            ->assertRedirect(route('member.login.step-up'));

        $emptyKeyCode = Totp::code('', intdiv(time(), 30));   // 비밀키가 비어 있으면 누구나 계산할 수 있는 값
        $this->post(route('member.login.two-factor'), ['code' => $emptyKeyCode])->assertSessionHasErrors('code');
        $this->assertGuest('member');
        $this->assertNull(Totp::verify('', $emptyKeyCode));
    }

    public function test_code_step_cannot_replace_otp(): void
    {
        $member = $this->makeMember('01033334444', 'moon-light-55');
        $member->forceFill(['two_factor_secret' => Totp::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->post(route('member.login.attempt'), ['identifier' => '01033334444', 'password' => 'moon-light-55'])
            ->assertRedirect(route('member.login.two-factor'));

        $this->post(route('member.login.step-up'), ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest('member');
    }

    public function test_email_signup_does_not_inherit_benefits_of_an_unverified_phone(): void
    {
        $victim = Customer::forPhone('01022223333');   // 남이 그 번호로 쌓아 둔 혜택
        $victim->update(['mileage_balance' => 50000]);

        $this->post(route('member.signup.start'), [
            'name' => '공격자', 'country' => 'KR', 'residence_country' => 'KR', 'phone' => '010-2222-3333',
            'channel' => 'email', 'email' => 'attacker@example.com',
            'password' => 'blue-ocean-77', 'password_confirmation' => 'blue-ocean-77', 'agree_terms' => 1, 'agree_privacy' => 1,
        ])->assertSessionHasNoErrors();
        $this->post(route('member.signup.verify'), ['code' => session('dev_code')])->assertRedirect(route('member.account'));

        $m = Member::first();
        $this->assertNull($m->phone_verified_at);
        $this->assertNotSame($victim->id, $m->customer_id);       // 번호의 기존 지갑이 아니라
        $this->assertSame(0, $m->customer->mileage_balance);       // 빈 새 지갑
        $this->assertNull($m->customer->smsPhone());                // 문자를 보낼 번호도 아니다
        $this->assertSame(50000, $victim->fresh()->mileage_balance);
    }

    public function test_dev_code_is_not_shown_unless_enabled(): void
    {
        config(['siren.show_dev_codes' => false]);
        $this->makeMember('01011112222', 'first-pass-12');

        $this->post(route('member.password.send'), ['identifier' => '01011112222']);
        $this->assertSame(1, DB::table('one_time_codes')->count());   // 코드는 만들어졌지만
        $this->assertNull(session('dev_code'));                        // 화면에는 내보내지 않는다

        $this->post(route('benefits.send'), ['phone' => '010-1111-2222']);
        $this->assertNull(session('dev_code'));
    }

    public function test_code_is_discarded_after_five_wrong_tries(): void
    {
        $this->makeMember('01011112222', 'first-pass-12');
        $this->post(route('member.password.send'), ['identifier' => '01011112222']);
        $code = session('dev_code');
        $wrong = $code === '111111' ? '222222' : '111111';

        foreach (range(1, 5) as $i) {
            $this->post(route('member.password.reset'), ['code' => $wrong, 'password' => 'second-pass-34', 'password_confirmation' => 'second-pass-34'])
                ->assertSessionHasErrors('code');
        }
        // 다섯 번 틀린 뒤에는 맞는 코드도 받지 않는다
        $this->post(route('member.password.reset'), ['code' => $code, 'password' => 'second-pass-34', 'password_confirmation' => 'second-pass-34'])
            ->assertSessionHasErrors('code');
        $this->assertSame(5, (int) DB::table('one_time_codes')->value('attempts'));
    }

    public function test_cut_off_session_loses_its_ticket_and_order_lists(): void
    {
        $member = $this->actingAsMember($this->makeMember('01099990000', 'old-secret-88'));
        $this->withSession(['ticket_nos' => ['T-AAAA1111'], 'order_nos' => ['O-BBBB2222']]);
        $this->get(route('member.account'))->assertOk();

        $member->forceFill(['session_version' => $member->session_version + 1])->save();   // 모든 기기 로그아웃
        $this->get(route('member.account'))->assertRedirect(route('member.login'));
        $this->assertGuest('member');
        $this->assertFalse(session()->has('ticket_nos'));
        $this->assertFalse(session()->has('order_nos'));
    }

    public function test_two_factor_cannot_be_overwritten_while_on(): void
    {
        $member = $this->makeMember('01033334444', 'moon-light-55');
        $secret = Totp::newSecret();
        $member->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $this->actingAsMember($member);

        $this->post(route('member.two-factor.setup'))->assertSessionHasErrors('code');
        $this->assertFalse(session()->has('member_2fa_setup'));
        $this->assertSame($secret, $member->fresh()->two_factor_secret);
    }

    public function test_staff_password_change_cuts_other_logged_in_sessions(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'web')->get(route('admin.check-in'))->assertOk();

        $admin->forceFill(['password' => 'brand-new-pass-99'])->save();   // 다른 곳에서 비밀번호를 재설정
        $this->get(route('admin.check-in'))->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_pages_carry_basic_security_headers(): void
    {
        $this->get(route('member.login'))
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
