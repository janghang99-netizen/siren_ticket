<?php

namespace App\Services\Auth;

use App\Services\OrderException;
use Illuminate\Support\Facades\Http;

/**
 * SNS 로그인 (OAuth 2.0 인가 코드 방식) — 네이버 · 카카오 · 구글.
 * 키는 .env (NAVER_CLIENT_ID 등). 키가 없는 SNS 는 버튼이 안 보인다.
 * 콜백 주소: {APP_URL}/member/oauth/{provider}/callback — 각 SNS 개발자 콘솔에 등록해야 한다.
 */
class SocialAuth
{
    public const PROVIDERS = ['naver' => '네이버', 'kakao' => '카카오', 'google' => '구글'];

    private const ENDPOINTS = [
        'naver' => ['https://nid.naver.com/oauth2.0/authorize', 'https://nid.naver.com/oauth2.0/token', 'https://openapi.naver.com/v1/nid/me'],
        'kakao' => ['https://kauth.kakao.com/oauth/authorize', 'https://kauth.kakao.com/oauth/token', 'https://kapi.kakao.com/v2/user/me'],
        'google' => ['https://accounts.google.com/o/oauth2/v2/auth', 'https://oauth2.googleapis.com/token', 'https://openidconnect.googleapis.com/v1/userinfo'],
    ];

    /** @return array<string, string> 키가 설정된 SNS */
    public static function enabled(): array
    {
        return array_filter(self::PROVIDERS, fn ($label, $p) => filled(config("services.{$p}.client_id")), ARRAY_FILTER_USE_BOTH);
    }

    public static function isEnabled(string $provider): bool
    {
        return array_key_exists($provider, self::enabled());
    }

    public function authorizeUrl(string $provider, string $state): string
    {
        $q = [
            'response_type' => 'code',
            'client_id' => config("services.{$provider}.client_id"),
            'redirect_uri' => $this->redirectUri($provider),
            'state' => $state,
        ];
        if ($provider === 'google') {
            $q['scope'] = 'openid email profile';
        }

        return self::ENDPOINTS[$provider][0].'?'.http_build_query($q);
    }

    /** 인가 코드 → 사용자 정보 @return array{id:string,name:?string,email:?string} */
    public function user(string $provider, string $code, string $state): array
    {
        [, $tokenUrl, $meUrl] = self::ENDPOINTS[$provider];
        $form = array_filter([
            'grant_type' => 'authorization_code',
            'client_id' => config("services.{$provider}.client_id"),
            'client_secret' => config("services.{$provider}.client_secret"),
            'redirect_uri' => $this->redirectUri($provider),
            'code' => $code,
            'state' => $provider === 'naver' ? $state : null,
        ], fn ($v) => filled($v));

        $token = Http::asForm()->timeout(10)->post($tokenUrl, $form);
        $access = $token->json('access_token');
        if (! $token->successful() || ! $access) {
            throw new OrderException('SNS 로그인에 실패했어요. 잠시 뒤 다시 해 주세요.', 'social_token');
        }
        $me = Http::withToken($access)->timeout(10)->get($meUrl);
        if (! $me->successful()) {
            throw new OrderException('SNS 계정 정보를 받지 못했어요. 잠시 뒤 다시 해 주세요.', 'social_profile');
        }

        $u = match ($provider) {
            'naver' => ['id' => $me->json('response.id'), 'name' => $me->json('response.name') ?? $me->json('response.nickname'), 'email' => $me->json('response.email')],
            'kakao' => ['id' => $me->json('id'), 'name' => $me->json('kakao_account.profile.nickname'), 'email' => $me->json('kakao_account.email')],
            'google' => ['id' => $me->json('sub'), 'name' => $me->json('name'), 'email' => $me->json('email_verified') === false ? null : $me->json('email')],
        };
        if (blank($u['id'])) {
            throw new OrderException('SNS 계정 정보를 받지 못했어요.', 'social_profile');
        }

        return ['id' => (string) $u['id'], 'name' => $u['name'] ? mb_substr((string) $u['name'], 0, 50) : null, 'email' => $u['email'] ? mb_strtolower((string) $u['email']) : null];
    }

    private function redirectUri(string $provider): string
    {
        return route('member.oauth.callback', $provider);
    }
}
