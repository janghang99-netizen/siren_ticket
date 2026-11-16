@extends('layouts.fan')
@section('title', __('로그인'))

@section('content')
    <p class="eyebrow mt-2">{{ __('로그인') }}</p>
    <h1 class="mt-2 text-2xl font-black">{{ __('회원 로그인') }}</h1>

    <form method="POST" action="{{ route('member.login.attempt') }}" class="mt-6 space-y-4 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
        @csrf
        <div>
            <label for="identifier" class="label">{{ __('휴대폰 번호 또는 이메일') }}</label>
            <input id="identifier" name="identifier" class="field" value="{{ old('identifier') }}" autocomplete="username" placeholder="010-1234-5678 / +86 138… / name@example.com" required autofocus>
        </div>
        <div>
            <label for="password" class="label">{{ __('비밀번호') }}</label>
            <input id="password" name="password" type="password" class="field" autocomplete="current-password" required>
        </div>
        @error('identifier')<p role="alert" class="rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal">{{ __($message) }}</p>@enderror
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remember" value="1"> {{ __('로그인 유지 (내 기기에서만)') }}</label>
        <button class="btn-ink w-full">{{ __('로그인') }}</button>
        <div class="flex justify-between text-sm">
            <a href="{{ route('member.password.forgot') }}" class="row-link text-mist">{{ __('비밀번호를 잊었어요') }}</a>
            <a href="{{ route('member.signup') }}" class="row-link">{{ __('회원가입') }}</a>
        </div>
    </form>
    @include('member.partials.social-buttons', ['title' => 'SNS 계정으로 로그인'])
    <p class="mt-4 text-xs leading-relaxed text-mist">{{ __('처음 쓰는 기기에서 로그인하면 휴대폰이나 이메일로 인증번호를 한 번 더 받아요. 여러 번 틀리면 계정이 잠시 잠겨요.') }}</p>
    {{-- 운영사·입점업체 계정은 팬 회원과 따로 — 관리 화면 로그인으로 --}}
    <a href="{{ route('login') }}" class="mt-4 flex items-center justify-between rounded-2xl bg-white/70 px-4 py-3 text-sm font-semibold ring-1 ring-ink/10">
        <span>운영사·입점업체이신가요? <span class="font-normal text-mist">관리 화면 로그인</span></span><span aria-hidden="true">→</span>
    </a>
@endsection
