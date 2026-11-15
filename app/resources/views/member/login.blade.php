@extends('layouts.fan')
@section('title', '로그인')

@section('content')
    <p class="eyebrow mt-2">로그인 · 登录</p>
    <h1 class="mt-2 text-2xl font-black">회원 로그인</h1>

    <form method="POST" action="{{ route('member.login.attempt') }}" class="mt-6 space-y-4 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
        @csrf
        <div>
            <label for="identifier" class="label">휴대폰 번호 또는 이메일 · 手机号或邮箱</label>
            <input id="identifier" name="identifier" class="field" value="{{ old('identifier') }}" autocomplete="username" placeholder="010-1234-5678 / +86 138… / name@qq.com" required autofocus>
        </div>
        <div>
            <label for="password" class="label">비밀번호 · 密码</label>
            <input id="password" name="password" type="password" class="field" autocomplete="current-password" required>
        </div>
        @error('identifier')<p role="alert" class="rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal">{{ $message }}</p>@enderror
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remember" value="1"> 로그인 유지 (내 기기에서만)</label>
        <button class="btn-ink w-full">로그인</button>
        <div class="flex justify-between text-sm">
            <a href="{{ route('member.password.forgot') }}" class="row-link text-mist">비밀번호를 잊었어요 · 忘记密码</a>
            <a href="{{ route('member.signup') }}" class="row-link">회원가입 · 注册</a>
        </div>
    </form>
    <p class="mt-4 text-xs leading-relaxed text-mist">처음 쓰는 기기에서 로그인하면 휴대폰이나 이메일로 인증번호를 한 번 더 받아요. 여러 번 틀리면 계정이 잠시 잠겨요. · 在新设备登录时需要额外验证。</p>
@endsection
