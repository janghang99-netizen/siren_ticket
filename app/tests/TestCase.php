<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** 테스트용 팬 회원 (휴대폰 인증 완료). 티켓 예매는 회원만 할 수 있다. */
    protected function makeMember(string $phone = '01012345678', string $password = 'siren-pass-2026', array $attrs = []): \App\Models\Member
    {
        $m = new \App\Models\Member($attrs + [
            'name' => '팬', 'country' => 'KR', 'phone' => $phone, 'phone_verified_at' => now(),
            'customer_id' => \App\Models\Customer::forPhone($phone)->id,
        ]);
        $m->forceFill(['password' => $password])->save();

        return $m;
    }

    /** 회원으로 로그인한 상태 (세션 버전까지 맞춰 EnsureMemberSession 통과) */
    protected function actingAsMember(?\App\Models\Member $member = null): \App\Models\Member
    {
        $member ??= $this->makeMember();
        $this->actingAs($member, 'member')->withSession(['member_session_version' => $member->session_version]);

        return $member;
    }
}
