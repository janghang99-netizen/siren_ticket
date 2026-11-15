<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** 비밀번호 변경·모든 기기 로그아웃 뒤에는 다른 기기의 회원 세션을 바로 끊는다 (세션 버전 비교). */
class EnsureMemberSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('member');
        if (! $guard->check()) {
            return $next($request);
        }
        $version = $request->session()->get('member_session_version');
        // "로그인 유지" 쿠키로 새 세션이 열리면 버전 값이 없다 → 지금 버전으로 기록
        // (비밀번호를 바꾸면 remember_token 도 바뀌어 예전 쿠키로는 애초에 로그인되지 않는다)
        if ($version === null) {
            $request->session()->put('member_session_version', $guard->user()->session_version);
        } elseif ((int) $version !== (int) $guard->user()->session_version) {
            $guard->logout();
            $request->session()->forget('member_session_version');

            return redirect()->route('member.login')->with('status', '보안을 위해 다시 로그인해 주세요. (비밀번호가 바뀌었거나 모든 기기에서 로그아웃했어요)');
        }

        return $next($request);
    }
}
