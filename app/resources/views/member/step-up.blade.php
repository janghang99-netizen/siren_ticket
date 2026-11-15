@extends('layouts.fan')
@section('title', '새 기기 인증')

@section('content')
    <p class="eyebrow mt-2">보안 · 安全验证</p>
    <h1 class="mt-2 text-2xl font-black">처음 보는 기기예요</h1>
    <p class="mt-2 text-sm text-ink/70">계정을 지키려고 <b>{{ $to }}</b>({{ $channel === 'email' ? '이메일' : '문자' }})로 인증번호를 보냈어요. 인증하면 이 기기는 30일 동안 다시 묻지 않아요.</p>

    <form method="POST" action="{{ route('member.login.step-up') }}" class="mt-6 flex gap-2">
        @csrf
        <label for="code" class="sr-only">인증번호</label>
        <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="field num tracking-[0.3em]" placeholder="000000" required autofocus>
        <button class="btn-ink shrink-0 !px-5">확인</button>
    </form>
    @error('code')<p class="mt-2 text-sm font-semibold text-signal">{{ $message }}</p>@enderror
    @if (session('dev_code'))
        <p class="mt-3 rounded-2xl bg-pick/30 px-3 py-2 text-xs">개발용 인증번호 <b class="num text-sm">{{ session('dev_code') }}</b></p>
    @endif
    <p class="mt-6 text-xs text-mist">내가 로그인한 게 아니라면 이 화면을 닫고 비밀번호를 바꿔 주세요. 인증번호는 누구에게도 알려 주지 마세요.</p>
@endsection
