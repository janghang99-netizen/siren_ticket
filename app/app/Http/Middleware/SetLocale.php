<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * 화면 언어: 고른 언어(세션) > 회원 설정 > 브라우저 언어 > 한국어.
 * 번역문은 lang/{locale}.json — 한국어 문장을 키로 쓰므로 번역이 빠진 문구는 한국어로 보인다.
 * (자동 번역 서비스는 쓰지 않는다: 중국에서 막히고, 약관·취소 규정 같은 문구는 정확해야 한다)
 */
class SetLocale
{
    public const SUPPORTED = ['ko' => '한국어', 'zh_CN' => '中文', 'en' => 'English'];

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(self::resolve($request));

        return $next($request);
    }

    public static function resolve(Request $request): string
    {
        $chosen = $request->hasSession() ? $request->session()->get('locale') : null;
        if ($chosen && isset(self::SUPPORTED[$chosen])) {
            return $chosen;
        }
        $member = $request->user('member');
        if ($member && isset(self::SUPPORTED[$member->locale])) {
            return $member->locale;
        }
        foreach ($request->getLanguages() as $lang) {
            $lang = strtolower(str_replace('-', '_', $lang));
            if (str_starts_with($lang, 'zh')) {
                return 'zh_CN';
            }
            if (str_starts_with($lang, 'ko')) {
                return 'ko';
            }
            if (str_starts_with($lang, 'en')) {
                return 'en';
            }
        }

        return 'ko';
    }
}
