@extends('layouts.fan')
@section('title', __('내 계정'))

@section('content')
    <div class="mt-2 flex items-start justify-between">
        <div>
            <p class="eyebrow">{{ __('내 계정') }}</p>
            <h1 class="mt-2 text-2xl font-black">{{ $member->name }}</h1>
            <p class="num text-sm text-mist">{{ $member->maskedPhone() }}{{ $member->email ? ' · '.$member->maskedEmail() : '' }}</p>
        </div>
        <form method="POST" action="{{ route('member.logout') }}">@csrf<button class="btn-ghost !px-4 !py-2 text-sm">{{ __('로그아웃') }}</button></form>
    </div>

    <div class="mt-5 grid grid-cols-2 gap-2 text-sm">
        @if ($ticketing)
            <a href="{{ route('tickets') }}" class="rounded-3xl bg-white px-5 py-4 font-bold ring-1 ring-ink/5">{{ __('내 티켓') }} →</a>
        @else
            <a href="{{ route('orders.index') }}" class="rounded-3xl bg-white px-5 py-4 font-bold ring-1 ring-ink/5">{{ __('주문내역') }} →</a>
        @endif
        <a href="{{ route('benefits') }}" class="rounded-3xl bg-white px-5 py-4 font-bold ring-1 ring-ink/5">{{ __('마일리지·쿠폰') }} →</a>
    </div>

    {{-- 보안 상태 --}}
    <section class="mt-6 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
        <h2 class="font-bold">{{ __('보안') }}</h2>
        <ul class="mt-3 space-y-2 text-sm">
            <li class="flex justify-between"><span>{{ __('휴대폰 인증') }}</span><span @class(['font-semibold', 'text-mist' => ! $member->phone_verified_at])>{{ $member->phone_verified_at ? __('완료') : __('안 함') }}</span></li>
            <li class="flex justify-between"><span>{{ __('이메일 인증') }}</span><span @class(['font-semibold', 'text-mist' => ! $member->email_verified_at])>{{ $member->email_verified_at ? __('완료') : ($member->email ? __('안 함') : __('없음')) }}</span></li>
            <li class="flex justify-between"><span>{{ __('2단계 인증 (OTP 앱)') }}</span><span @class(['font-semibold', 'text-mist' => ! $member->hasTwoFactor()])>{{ $member->hasTwoFactor() ? __('켜짐') : __('꺼짐') }}</span></li>
            <li class="flex justify-between"><span>{{ __('사는 나라') }}</span><span class="font-semibold">{{ __($member->residenceLabel()) }}</span></li>
            <li class="flex justify-between"><span>{{ __('비밀번호 변경') }}</span><span class="num text-mist">{{ $member->password_changed_at?->format('Y.m.d') ?? '—' }}</span></li>
        </ul>
    </section>

    {{-- 2단계 인증 --}}
    {{-- SNS 로그인 연결 (네이버·카카오·구글) --}}
    @php($linked = $member->socialAccounts()->pluck('provider')->all())
    @php($providers = \App\Services\Auth\SocialAuth::enabled())
    @if ($providers || $linked)
        <section class="mt-4 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
            <h2 class="font-bold">SNS 로그인 연결</h2>
            @error('social')<p role="alert" class="mt-2 text-sm font-semibold text-signal">{{ $message }}</p>@enderror
            <ul class="mt-3 space-y-2 text-sm">
                @foreach (\App\Services\Auth\SocialAuth::PROVIDERS as $p => $label)
                    @continue(! isset($providers[$p]) && ! in_array($p, $linked, true))
                    <li class="flex items-center justify-between gap-3 rounded-2xl bg-paper px-4 py-2.5">
                        <span><b>{{ $label }}</b> <span class="text-mist">{{ in_array($p, $linked, true) ? '연결됨' : '연결 안 됨' }}</span></span>
                        @if (in_array($p, $linked, true))
                            <form method="POST" action="{{ route('member.social.unlink', $p) }}" onsubmit="return confirm('{{ $label }} 연결을 끊을까요? 휴대폰 번호·비밀번호로는 계속 로그인할 수 있어요.')">
                                @csrf @method('DELETE')
                                <button class="text-xs font-semibold text-signal">연결 끊기</button>
                            </form>
                        @else
                            <a href="{{ route('member.oauth', $p) }}" class="text-xs font-semibold text-stick">연결하기</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="mt-4 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
        <h2 class="font-bold">{{ __('2단계 인증') }} <span class="text-xs font-normal text-mist">{{ __('비밀번호가 새도 계정을 지켜요 (권장)') }}</span></h2>
        @if ($recoveryCodes)
            <div class="mt-3 rounded-2xl bg-pick/30 p-4 text-sm">
                <p class="font-bold">{{ __('복구 코드 — 지금 한 번만 보여요. 안전한 곳에 적어 두세요.') }}</p>
                <p class="mt-1 text-xs">{{ __('휴대폰을 잃어버렸을 때 OTP 코드 대신 하나씩 쓸 수 있어요.') }}</p>
                <ul class="num mt-2 grid grid-cols-2 gap-1 text-sm">@foreach ($recoveryCodes as $c)<li>{{ $c }}</li>@endforeach</ul>
            </div>
        @endif
        @if ($member->hasTwoFactor())
            <form method="POST" action="{{ route('member.two-factor.disable') }}" class="mt-3 grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                @csrf @method('DELETE')
                <input name="password" type="password" class="field !py-2 text-sm" placeholder="{{ __('비밀번호') }}" autocomplete="current-password" required aria-label="{{ __('비밀번호') }}">
                <input name="code" class="field num !py-2 text-sm" placeholder="{{ __('OTP 코드') }}" required aria-label="{{ __('OTP 코드') }}">
                <button class="btn-ghost !py-2 text-sm">{{ __('끄기') }}</button>
            </form>
            @error('disable')<p class="mt-1 text-sm text-signal">{{ __($message) }}</p>@enderror
        @elseif ($setupSecret)
            @php($uri = \App\Services\Auth\Totp::uri($setupSecret, $member->email ?? $member->phone))
            <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm">
                <li>{{ __('OTP 앱(Google Authenticator, Microsoft Authenticator 등)에서 "키 직접 입력"을 고르세요.') }}</li>
                <li>{{ __('아래 키를 입력하세요.') }} <a href="{{ $uri }}" class="row-link">{{ __('휴대폰이면 여기를 눌러 앱으로 열기') }}</a></li>
            </ol>
            <p class="num mt-2 break-all rounded-2xl bg-paper px-4 py-3 text-center text-lg font-bold tracking-wider">{{ trim(chunk_split($setupSecret, 4, ' ')) }}</p>
            <form method="POST" action="{{ route('member.two-factor.confirm') }}" class="mt-3 flex gap-2">
                @csrf
                <input name="code" inputmode="numeric" maxlength="6" class="field num tracking-[0.3em]" placeholder="{{ __('앱의 6자리') }}" required aria-label="{{ __('OTP 코드') }}">
                <button class="btn-ink shrink-0 !px-5">{{ __('켜기') }}</button>
            </form>
            @error('code')<p class="mt-1 text-sm text-signal">{{ __($message) }}</p>@enderror
        @else
            <form method="POST" action="{{ route('member.two-factor.setup') }}" class="mt-3">@csrf<button class="btn-ink !py-2.5 text-sm">{{ __('2단계 인증 켜기') }}</button></form>
        @endif
    </section>

    {{-- 거주 국가 --}}
    <section class="mt-4 rounded-3xl bg-white p-5 ring-1 ring-ink/5" x-data="{ r: @js($member->residence_country) }">
        <h2 class="font-bold">{{ __('사는 나라') }} <span class="text-xs font-normal text-mist">{{ __('국적이 아니라 지금 사는 나라') }}</span></h2>
        <form method="POST" action="{{ route('member.residence') }}" class="mt-3 space-y-2">
            @csrf @method('PUT')
            <select name="residence_country" x-model="r" class="field !py-2.5" aria-label="{{ __('사는 나라') }}">
                @foreach (\App\Models\Member::COUNTRIES as $code => [, $label])<option value="{{ $code }}">{{ __($label) }}</option>@endforeach
            </select>
            <label x-show="r === 'CN'" class="flex items-start gap-2 text-xs"><input type="checkbox" name="agree_overseas" value="1" class="mt-0.5">
                <span>{{ __('개인정보를 대한민국으로 이전하는 데 동의해요 (중국 회원 필수)') }}</span></label>
            <input name="password" type="password" class="field !py-2.5" placeholder="{{ __('비밀번호 확인') }}" autocomplete="current-password" required aria-label="{{ __('비밀번호 확인') }}">
            @error('residence')<p class="text-sm text-signal">{{ __($message) }}</p>@enderror
            @error('agree_overseas')<p class="text-sm text-signal">{{ __($message) }}</p>@enderror
            <button class="btn-ghost !py-2 text-sm">{{ __('바꾸기') }}</button>
        </form>
    </section>

    {{-- 비밀번호 변경 --}}
    <section class="mt-4 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
        <h2 class="font-bold">{{ __('비밀번호 변경') }}</h2>
        <form method="POST" action="{{ route('member.password.change') }}" class="mt-3 space-y-2">
            @csrf @method('PUT')
            <input name="current_password" type="password" class="field !py-2.5" placeholder="{{ __('지금 비밀번호') }}" autocomplete="current-password" required aria-label="{{ __('지금 비밀번호') }}">
            <input name="password" type="password" class="field !py-2.5" placeholder="{{ __('새 비밀번호 (10자 이상, 영문+숫자)') }}" autocomplete="new-password" minlength="10" required aria-label="{{ __('새 비밀번호') }}">
            <input name="password_confirmation" type="password" class="field !py-2.5" placeholder="{{ __('새 비밀번호 확인') }}" autocomplete="new-password" minlength="10" required aria-label="{{ __('새 비밀번호 확인') }}">
            @error('current_password')<p class="text-sm text-signal">{{ __($message) }}</p>@enderror
            @error('password')<p class="text-sm text-signal">{{ __($message) }}</p>@enderror
            <button class="btn-ink !py-2.5 text-sm">{{ __('바꾸기') }}</button>
            <p class="text-xs text-mist">{{ __('바꾸면 이 기기를 뺀 모든 기기의 로그인이 끊겨요.') }}</p>
        </form>
    </section>

    {{-- 기기 --}}
    <section class="mt-4 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
        <div class="flex items-center justify-between">
            <h2 class="font-bold">{{ __('인증한 기기') }}</h2>
            <form method="POST" action="{{ route('member.logout-everywhere') }}" onsubmit="return confirm(@js(__('모든 기기에서 로그아웃할까요?')))">@csrf<button class="text-xs font-semibold text-signal">{{ __('모든 기기 로그아웃') }}</button></form>
        </div>
        <ul class="mt-3 space-y-2 text-sm">
            @forelse ($devices as $d)
                <li class="flex items-center justify-between gap-3">
                    <span>{{ $d->label ?: __('기기') }} @if ($d->id === $currentDevice)<span class="chip bg-ink/5">{{ __('이 기기') }}</span>@endif
                        <span class="num block text-xs text-mist">{{ $d->last_seen_at?->format('m.d H:i') }} · {{ $d->ip }}</span></span>
                    @unless ($d->id === $currentDevice)
                        <form method="POST" action="{{ route('member.devices.revoke', $d->id) }}">@csrf @method('DELETE')<button class="text-xs font-semibold text-mist hover:text-signal">{{ __('해제') }}</button></form>
                    @endunless
                </li>
            @empty
                <li class="text-mist">{{ __('인증한 기기가 없어요.') }}</li>
            @endforelse
        </ul>
    </section>

    {{-- 로그인 기록 --}}
    <section class="mt-4 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
        <h2 class="font-bold">{{ __('최근 로그인 기록') }} <span class="text-xs font-normal text-mist">{{ __('모르는 기록이 있으면 바로 비밀번호를 바꾸세요') }}</span></h2>
        <ul class="mt-3 divide-y divide-ink/5 text-sm">
            @foreach ($events as $e)
                <li class="flex items-center justify-between gap-3 py-2">
                    <span @class(['font-semibold', 'text-signal' => in_array($e->result, ['bad_password', 'locked', 'step_up_failed', 'two_factor_failed', 'throttled'])])>{{ __($e->label()) }}</span>
                    <span class="num text-right text-xs text-mist">{{ $e->created_at->format('m.d H:i') }}<br>{{ $e->ip }} · {{ \App\Services\Auth\MemberAuthService::deviceLabel((string) $e->user_agent) }}</span>
                </li>
            @endforeach
        </ul>
    </section>
@endsection
