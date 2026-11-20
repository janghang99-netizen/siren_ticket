<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 기본 보안 헤더.
 *  - 다른 사이트가 이 사이트를 틀(iframe) 안에 넣어 클릭을 가로채지 못하게
 *  - 브라우저가 파일 종류를 멋대로 추측해 실행하지 못하게
 *  - 다른 사이트로 나갈 때 주소(주문 번호 등)를 통째로 넘기지 않게
 *  - HTTPS 로 들어온 요청이면 이후에도 HTTPS 만 쓰게
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
