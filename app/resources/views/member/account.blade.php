@extends('layouts.fan')
@section('title', '내 계정')

@section('content')
    <div class="mt-2 flex items-start justify-between">
        <div>
            <p class="eyebrow">내 계정 · 我的账户</p>
            <h1 class="mt-2 text-2xl font-black">{{ $member->name }}</h1>
            <p class="num text-sm text-mist">{{ $member->maskedPhone() }}{{ $member->email ? ' · '.$member->maskedEmail() : '' }}</p>
        </div>
        <form method="POST" action="{{ route('member.logout') }}">@csrf<button class="btn-ghost !px-4 !py-2 text-sm">로그아웃</button></form>
    </div>

    <div class="mt-5 grid grid-cols-2 gap-2 text-sm">
        <a href="{{ route('tickets') }}" class="rounded-3xl bg-white px-5 py-4 font-bold ring-1 ring-ink/5">내 티켓 →</a>
        <a href="{{ route('benefits') }}" class="rounded-3xl bg-white px-5 py-4 font-bold ring-1 ring-ink/5">마일리지·쿠폰 →</a>
    </div>

    {{-- 보안 상태 --}}
    <section class="mt-6 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
        <h2 class="font-bold">보안 · 账户安全</h2>
        <ul class="mt-3 space-y-2 text-sm">
            <li class="flex justify-between"><span>휴대폰 인증</span><span @class(['font-semibold', 'text-mist' => ! $member->phone_verified_at])>{{ $member->phone_verified_at ? '완료' : '안 함' }}</span></li>
            <li class="flex justify-between"><span>이메일 인증</span><span @class(['font-semibold', 'text-mist' => ! $member->email_verified_at])>{{ $member->email_verified_at ? '완료' : ($member->email ? '안 함' : '없음') }}</span></li>
            <li class="flex justify-between"><span>2단계 인증 (OTP 앱)</span><span @class(['font-semibold', 'text-mist' => ! $member->hasTwoFactor()])>{{ $member->hasTwoFactor() ? '켜짐' : '꺼짐' }}</span></li>
            <li class="flex justify-between"><span>비밀번호 변경</span><span class="num text-mist">{{ $member->password_changed_at?->format('Y.m.d') ?? '—' }}</span></li>
        </ul>
    </section>

    {{-- 2단계 인증 --}}
    <section class="mt-4 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
        <h2 class="font-bold">2단계 인증 <span class="text-xs font-normal text-mist">비밀번호가 새도 계정을 지켜요 (권장)</span></h2>
        @if ($recoveryCodes)
            <div class="mt-3 rounded-2xl bg-pick/30 p-4 text-sm">
                <p class="font-bold">복구 코드 — 지금 한 번만 보여요. 안전한 곳에 적어 두세요.</p>
                <p class="mt-1 text-xs">휴대폰을 잃어버렸을 때 OTP 코드 대신 하나씩 쓸 수 있어요.</p>
                <ul class="num mt-2 grid grid-cols-2 gap-1 text-sm">@foreach ($recoveryCodes as $c)<li>{{ $c }}</li>@endforeach</ul>
            </div>
        @endif
        @if ($member->hasTwoFactor())
            <form method="POST" action="{{ route('member.two-factor.disable') }}" class="mt-3 grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                @csrf @method('DELETE')
                <input name="password" type="password" class="field !py-2 text-sm" placeholder="비밀번호" autocomplete="current-password" required aria-label="비밀번호">
                <input name="code" class="field num !py-2 text-sm" placeholder="OTP 코드" required aria-label="OTP 코드">
                <button class="btn-ghost !py-2 text-sm">끄기</button>
            </form>
            @error('disable')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        @elseif ($setupSecret)
            @php($uri = \App\Services\Auth\Totp::uri($setupSecret, $member->email ?? $member->phone))
            <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm">
                <li>OTP 앱(Google Authenticator, Microsoft Authenticator, 腾讯身份验证器 등)에서 "키 직접 입력"을 고르세요.</li>
                <li>아래 키를 입력하세요. (휴대폰에서 보고 있다면 <a href="{{ $uri }}" class="row-link">여기를 눌러 앱으로 열기</a>)</li>
            </ol>
            <p class="num mt-2 break-all rounded-2xl bg-paper px-4 py-3 text-center text-lg font-bold tracking-wider">{{ trim(chunk_split($setupSecret, 4, ' ')) }}</p>
            <form method="POST" action="{{ route('member.two-factor.confirm') }}" class="mt-3 flex gap-2">
                @csrf
                <input name="code" inputmode="numeric" maxlength="6" class="field num tracking-[0.3em]" placeholder="앱의 6자리" required aria-label="OTP 코드">
                <button class="btn-ink shrink-0 !px-5">켜기</button>
            </form>
            @error('code')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        @else
            <form method="POST" action="{{ route('member.two-factor.setup') }}" class="mt-3">@csrf<button class="btn-ink !py-2.5 text-sm">2단계 인증 켜기</button></form>
        @endif
    </section>

    {{-- 비밀번호 변경 --}}
    <section class="mt-4 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
        <h2 class="font-bold">비밀번호 변경</h2>
        <form method="POST" action="{{ route('member.password.change') }}" class="mt-3 space-y-2">
            @csrf @method('PUT')
            <input name="current_password" type="password" class="field !py-2.5" placeholder="지금 비밀번호" autocomplete="current-password" required aria-label="지금 비밀번호">
            <input name="password" type="password" class="field !py-2.5" placeholder="새 비밀번호 (10자 이상, 영문+숫자)" autocomplete="new-password" minlength="10" required aria-label="새 비밀번호">
            <input name="password_confirmation" type="password" class="field !py-2.5" placeholder="새 비밀번호 확인" autocomplete="new-password" minlength="10" required aria-label="새 비밀번호 확인">
            @error('current_password')<p class="text-sm text-signal">{{ $message }}</p>@enderror
            @error('password')<p class="text-sm text-signal">{{ $message }}</p>@enderror
            <button class="btn-ink !py-2.5 text-sm">바꾸기</button>
            <p class="text-xs text-mist">바꾸면 이 기기를 뺀 모든 기기의 로그인이 끊겨요.</p>
        </form>
    </section>

    {{-- 기기 --}}
    <section class="mt-4 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
        <div class="flex items-center justify-between">
            <h2 class="font-bold">인증한 기기</h2>
            <form method="POST" action="{{ route('member.logout-everywhere') }}" onsubmit="return confirm('모든 기기에서 로그아웃할까요?')">@csrf<button class="text-xs font-semibold text-signal">모든 기기 로그아웃</button></form>
        </div>
        <ul class="mt-3 space-y-2 text-sm">
            @forelse ($devices as $d)
                <li class="flex items-center justify-between gap-3">
                    <span>{{ $d->label ?: '기기' }} @if ($d->id === $currentDevice)<span class="chip bg-ink/5">이 기기</span>@endif
                        <span class="num block text-xs text-mist">{{ $d->last_seen_at?->format('m.d H:i') }} · {{ $d->ip }}</span></span>
                    @unless ($d->id === $currentDevice)
                        <form method="POST" action="{{ route('member.devices.revoke', $d->id) }}">@csrf @method('DELETE')<button class="text-xs font-semibold text-mist hover:text-signal">해제</button></form>
                    @endunless
                </li>
            @empty
                <li class="text-mist">인증한 기기가 없어요.</li>
            @endforelse
        </ul>
    </section>

    {{-- 로그인 기록 --}}
    <section class="mt-4 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
        <h2 class="font-bold">최근 로그인 기록 <span class="text-xs font-normal text-mist">모르는 기록이 있으면 바로 비밀번호를 바꾸세요</span></h2>
        <ul class="mt-3 divide-y divide-ink/5 text-sm">
            @foreach ($events as $e)
                <li class="flex items-center justify-between gap-3 py-2">
                    <span @class(['font-semibold', 'text-signal' => in_array($e->result, ['bad_password', 'locked', 'step_up_failed', 'two_factor_failed', 'throttled'])])>{{ $e->label() }}</span>
                    <span class="num text-right text-xs text-mist">{{ $e->created_at->format('m.d H:i') }}<br>{{ $e->ip }} · {{ \App\Services\Auth\MemberAuthService::deviceLabel((string) $e->user_agent) }}</span>
                </li>
            @endforeach
        </ul>
    </section>
@endsection
