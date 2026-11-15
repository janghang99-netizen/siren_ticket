@extends('layouts.fan')
@section('title', __('비밀번호 재설정'))

@section('content')
    <p class="eyebrow mt-2">{{ __('비밀번호 재설정') }}</p>
    <h1 class="mt-2 text-2xl font-black">{{ __('비밀번호를 잊었어요') }}</h1>

    <form method="POST" action="{{ route('member.password.send') }}" class="mt-6 flex gap-2">
        @csrf
        <label for="identifier" class="sr-only">{{ __('휴대폰 번호 또는 이메일') }}</label>
        <input id="identifier" name="identifier" class="field" placeholder="{{ __('휴대폰 번호 또는 이메일') }}" value="{{ old('identifier') }}" required>
        <button class="btn-ink shrink-0 !px-4 text-sm">{{ __('인증번호 받기') }}</button>
    </form>
    @error('identifier')<p class="mt-2 text-sm font-semibold text-signal">{{ __($message) }}</p>@enderror

    @if ($pending)
        <form method="POST" action="{{ route('member.password.reset') }}" class="mt-6 space-y-3 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
            @csrf
            <div><label for="code" class="label">{{ __('인증번호') }}</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="field num tracking-[0.3em]" required></div>
            <div><label for="password" class="label">{{ __('새 비밀번호 (10자 이상, 영문+숫자)') }}</label><input id="password" name="password" type="password" class="field" autocomplete="new-password" minlength="10" required></div>
            <div><label for="password_confirmation" class="label">{{ __('새 비밀번호 확인') }}</label><input id="password_confirmation" name="password_confirmation" type="password" class="field" autocomplete="new-password" minlength="10" required></div>
            @error('code')<p class="text-sm text-signal">{{ __($message) }}</p>@enderror
            @error('password')<p class="text-sm text-signal">{{ __($message) }}</p>@enderror
            @if (session('dev_code'))<p class="rounded-2xl bg-pick/30 px-3 py-2 text-xs">{{ __('개발용 인증번호') }} <b class="num text-sm">{{ session('dev_code') }}</b></p>@endif
            <button class="btn-ink w-full">{{ __('비밀번호 바꾸기') }}</button>
            <p class="text-xs text-mist">{{ __('바꾸면 다른 모든 기기의 로그인이 끊겨요.') }}</p>
        </form>
    @endif
@endsection
