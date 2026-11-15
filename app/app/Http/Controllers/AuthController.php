<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** 관리 화면 로그인 — 슈퍼어드민은 관리자 콘솔, 입점업체는 파트너 콘솔로 */
class AuthController extends Controller
{
    public function form(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $cred = $request->validate(['email' => ['required', 'email'], 'password' => ['required']]);

        if (! Auth::attempt($cred, true)) {
            return back()->withInput($request->only('email'))->withErrors(['email' => '이메일 또는 비밀번호가 맞지 않아요.']);
        }

        $user = Auth::user();
        if (! $user->isSuperAdmin() && ! ($user->isVendor() && $user->vendor?->isActive())) {
            Auth::logout();

            return back()->withInput($request->only('email'))->withErrors(['email' => '이용이 정지된 계정이에요. 운영사에 문의해 주세요.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(self::home($user));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public static function home($user): string
    {
        return $user?->isSuperAdmin() ? route('admin.events.index') : route('partner.board');
    }
}
