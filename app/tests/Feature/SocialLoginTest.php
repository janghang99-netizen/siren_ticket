<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberSocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** SNS 로그인 (카카오로 대표 확인 — 네이버·구글도 같은 흐름) */
class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.kakao.client_id' => 'test-kakao-key', 'services.naver.client_id' => null, 'services.google.client_id' => null]);
        Http::fake([
            'kauth.kakao.com/oauth/token' => Http::response(['access_token' => 'kakao-access']),
            'kapi.kakao.com/v2/user/me' => Http::response(['id' => 777001, 'kakao_account' => ['profile' => ['nickname' => '카카오팬'], 'email' => 'fan@example.com']]),
        ]);
    }

    /** 카카오 동의 화면까지 갔다가 돌아온 것처럼 */
    private function kakaoCallback(): \Illuminate\Testing\TestResponse
    {
        $go = $this->get(route('member.oauth', 'kakao'));
        $go->assertRedirectContains('kauth.kakao.com/oauth/authorize');
        parse_str(parse_url($go->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame(route('member.oauth.callback', 'kakao'), $q['redirect_uri']);

        return $this->get(route('member.oauth.callback', ['kakao', 'code' => 'abc', 'state' => $q['state']]));
    }

    public function test_buttons_only_for_providers_with_keys(): void
    {
        $this->get(route('member.login'))->assertSee('카카오로 계속하기')->assertDontSee('네이버로 계속하기');
        $this->get(route('member.oauth', 'naver'))->assertNotFound();
    }

    public function test_first_time_kakao_user_signs_up_with_phone_only(): void
    {
        $this->kakaoCallback()->assertRedirect(route('member.signup'));
        $this->get(route('member.signup'))->assertSee('카카오</b> 계정으로 가입 중', false)->assertSee('카카오팬')->assertDontSee('id="password"', false);

        $start = $this->post(route('member.signup.start'), [
            'name' => '카카오팬', 'country' => 'KR', 'residence_country' => 'KR', 'phone' => '010-3333-4444', 'email' => 'fan@example.com',
            'channel' => 'sms', 'agree_terms' => 1, 'agree_privacy' => 1,
        ])->assertSessionHasNoErrors();
        $this->post(route('member.signup.verify'), ['code' => $start->getSession()->get('dev_code')])->assertSessionHasNoErrors();

        $member = Member::where('phone', '01033334444')->firstOrFail();
        $this->assertAuthenticatedAs($member, 'member');
        $this->assertDatabaseHas('member_social_accounts', ['member_id' => $member->id, 'provider' => 'kakao', 'provider_user_id' => '777001']);
    }

    public function test_kakao_links_to_existing_member_after_phone_code(): void
    {
        $old = $this->makeMember('01055556666');
        $this->kakaoCallback()->assertRedirect(route('member.signup'));
        $start = $this->post(route('member.signup.start'), [
            'name' => '팬', 'country' => 'KR', 'residence_country' => 'KR', 'phone' => '010-5555-6666',
            'channel' => 'sms', 'agree_terms' => 1, 'agree_privacy' => 1,
        ])->assertSessionHasNoErrors();
        $this->post(route('member.signup.verify'), ['code' => $start->getSession()->get('dev_code')]);

        $this->assertSame(1, Member::count());   // 새 회원을 만들지 않고 기존 계정에 연결
        $this->assertAuthenticatedAs($old, 'member');
        $this->assertDatabaseHas('member_social_accounts', ['member_id' => $old->id, 'provider' => 'kakao']);
    }

    public function test_linked_kakao_logs_in_directly(): void
    {
        $m = $this->makeMember('01077778888');
        MemberSocialAccount::create(['member_id' => $m->id, 'provider' => 'kakao', 'provider_user_id' => '777001']);
        $this->kakaoCallback()->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($m, 'member');
    }

    public function test_wrong_state_is_refused(): void
    {
        $this->get(route('member.oauth', 'kakao'));
        $this->get(route('member.oauth.callback', ['kakao', 'code' => 'abc', 'state' => 'forged']))->assertRedirect(route('member.login'));
        $this->assertGuest('member');
        Http::assertNothingSent();
    }
}
