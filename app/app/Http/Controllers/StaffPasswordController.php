<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Vendor;
use App\Services\Audit;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * 관리 화면 계정(운영사·입점업체) 비밀번호 찾기·설정.
 * 다담다 이관: 원본 판매자 비밀번호는 옮길 수 없어서, 판매자 계정을 새로 만들고 운영사가 '비밀번호 설정 메일'을 보낸다.
 */
class StaffPasswordController extends Controller
{
    public function forgotForm(): View
    {
        return view('auth.forgot');
    }

    public function send(Request $request): RedirectResponse
    {
        $email = $request->validate(['email' => ['required', 'email']])['email'];
        // 관리 화면 계정만 (팬 회원은 회원 화면의 비밀번호 찾기)
        if (User::where('email', $email)->whereIn('role', ['super_admin', 'vendor'])->exists()) {
            Password::broker('users')->sendResetLink(['email' => $email]);
        }

        // 계정이 있는지 없는지 알려 주지 않는다 (이메일 확인 방지)
        return back()->with('status', '그 이메일로 가입한 관리 계정이 있으면 비밀번호 설정 메일을 보냈어요. 메일함을 확인해 주세요.');
    }

    public function resetForm(Request $request, string $token): View
    {
        return view('auth.reset', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ], [
            'password.confirmed' => '비밀번호 확인이 맞지 않아요.',
            'password.min' => '비밀번호는 10자 이상이어야 해요.',
            'password.letters' => '비밀번호에 영문을 넣어 주세요.',
            'password.numbers' => '비밀번호에 숫자를 넣어 주세요.',
        ]);

        $status = Password::broker('users')->reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
            Audit::log('staff.password_reset', $user, '관리 계정 비밀번호 설정');
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', '비밀번호를 설정했어요. 새 비밀번호로 로그인하세요.')
            : back()->withInput($request->only('email'))->withErrors(['email' => '링크가 맞지 않거나 시간이 지났어요. 비밀번호 찾기에서 다시 받아 주세요.']);
    }

    /** 운영사: 입점업체 로그인 계정에 비밀번호 설정 메일 보내기 (이관한 판매자 첫 로그인용) */
    public function vendorLink(Vendor $vendor): RedirectResponse
    {
        $users = $vendor->users()->where('role', 'vendor')->get();
        abort_if($users->isEmpty(), 422, '이 업체에는 로그인 계정이 없어요.');
        $sent = $users->filter(fn (User $u) => Password::broker('users')->sendResetLink(['email' => $u->email]) === Password::RESET_LINK_SENT);
        Audit::log('vendor.password_link', $vendor, "비밀번호 설정 메일 {$sent->count()}건");

        return back()->with('status', $sent->isNotEmpty()
            ? '비밀번호 설정 메일을 보냈어요: '.$sent->pluck('email')->join(', ')
            : '방금 보냈어요. 1분 뒤에 다시 보낼 수 있어요.');
    }
}
