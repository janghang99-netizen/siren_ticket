@extends('layouts.fan')
@section('title', __('2단계 인증'))

@section('content')
    <p class="eyebrow mt-2">{{ __('2단계 인증') }}</p>
    <h1 class="mt-2 text-2xl font-black">{{ __('OTP 앱 코드를 입력해 주세요') }}</h1>
    <p class="mt-2 text-sm text-ink/70">{{ __('OTP 앱(Google Authenticator 등)에 보이는 6자리 코드, 또는 복구 코드를 입력해 주세요.') }}</p>

    <form method="POST" action="{{ route('member.login.two-factor') }}" class="mt-6 flex gap-2">
        @csrf
        <label for="code" class="sr-only">{{ __('인증 코드') }}</label>
        <input id="code" name="code" autocomplete="one-time-code" maxlength="12" class="field num tracking-[0.2em]" placeholder="000000" required autofocus>
        <button class="btn-ink shrink-0 !px-5">{{ __('확인') }}</button>
    </form>
    @error('code')<p class="mt-2 text-sm font-semibold text-signal">{{ __($message) }}</p>@enderror
@endsection
