<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 개발·테스트 서버(SITE_NOINDEX=true): 모든 응답에 검색 엔진 수집·노출 금지 헤더 */
class BlockSearchIndexing
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (config('siren.noindex')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $response;
    }
}
