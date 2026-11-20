<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 관리 계정(운영사·업체·검표원): 비밀번호가 바뀌면 다른 기기에 남아 있던 로그인을 끊는다.
 * 로그인할 때의 비밀번호 지문을 세션에 적어 두고, 요청마다 지금 비밀번호와 비교한다. 팬 회원 로그인에는 관여하지 않는다.
 */
class EnsureStaffSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        if (! $request->hasSession() || ! $guard->check()) {
            return $next($request);
        }
        $user = $guard->user();
        $now = ['id' => $user->getAuthIdentifier(), 'sig' => hash('sha256', (string) $user->getAuthPassword())];
        $saved = $request->session()->get('staff_session');

        if (! is_array($saved) || ($saved['id'] ?? null) !== $now['id']) {
            $request->session()->put('staff_session', $now);   // 첫 요청 또는 다른 계정으로 로그인
        } elseif (! hash_equals((string) ($saved['sig'] ?? ''), $now['sig'])) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => '비밀번호가 바뀌어 다시 로그인해야 해요.'], 401)
                : redirect()->route('login')->with('status', '비밀번호가 바뀌었어요. 다시 로그인해 주세요.');
        }

        return $next($request);
    }
}
