@extends('layouts.fan')
@section('title', '회원가입')

@section('content')
    <p class="eyebrow mt-2">회원가입 · 注册</p>
    <h1 class="mt-2 text-2xl font-black">티켓 예매는 회원만 할 수 있어요</h1>

    @if ($pending)
        {{-- 2단계: 인증번호 --}}
        <section class="mt-6 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
            <p class="text-sm">
                <b>{{ $pending['channel'] === 'email' ? $pending['email'] : $pending['phone'] }}</b>
                (으)로 보낸 인증번호 6자리를 입력해 주세요. · 请输入验证码
            </p>
            <form method="POST" action="{{ route('member.signup.verify') }}" class="mt-4 flex gap-2">
                @csrf
                <label for="code" class="sr-only">인증번호</label>
                <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="field num tracking-[0.3em]" placeholder="000000" required autofocus>
                <button class="btn-ink shrink-0 !px-5">가입 완료</button>
            </form>
            @error('code')<p class="mt-2 text-sm font-semibold text-signal">{{ $message }}</p>@enderror
            @if (session('dev_code'))
                <p class="mt-3 rounded-2xl bg-pick/30 px-3 py-2 text-xs">개발용 인증번호 <b class="num text-sm">{{ session('dev_code') }}</b> · 실서비스에서는 {{ $pending['channel'] === 'email' ? '메일' : '문자' }}로 보내요</p>
            @endif
            <p class="mt-4 text-xs text-mist">못 받았나요? 10분 뒤 만료돼요. 아래에서 처음부터 다시 할 수 있어요.</p>
        </section>
    @endif

    <form method="POST" action="{{ route('member.signup.start') }}" class="mt-6 space-y-4 rounded-3xl bg-white p-5 ring-1 ring-ink/5"
          x-data="{ country: @js(old('country', 'KR')), channel: @js(old('channel', 'sms')) }"
          x-init="$watch('country', c => channel = (c === 'KR' ? 'sms' : 'email'))">
        @csrf
        <div>
            <label for="name" class="label">이름 · 姓名 <span class="font-normal text-mist">(티켓 예매자 이름)</span></label>
            <input id="name" name="name" class="field" value="{{ old('name') }}" autocomplete="name" required>
            @error('name')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        </div>
        <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)] gap-2">
            <div>
                <label for="country" class="label">국가 · 国家</label>
                <select id="country" name="country" x-model="country" class="field">
                    @foreach (\App\Models\Member::COUNTRIES as $code => [$dial, $label])
                        <option value="{{ $code }}">+{{ $dial }} {{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="phone" class="label">휴대폰 · 手机号</label>
                <input id="phone" name="phone" class="field num" inputmode="tel" value="{{ old('phone') }}" autocomplete="tel-national"
                       :placeholder="country === 'KR' ? '010-1234-5678' : (country === 'CN' ? '138 0013 8000' : '')" required>
            </div>
        </div>
        @error('phone')<p class="-mt-2 text-sm text-signal">{{ $message }}</p>@enderror

        <div>
            <label for="email" class="label">이메일 · 邮箱 <span class="font-normal text-mist" x-text="channel === 'email' ? '(인증에 써요)' : '(선택)'"></span></label>
            <input id="email" name="email" type="email" class="field" value="{{ old('email') }}" autocomplete="email" :required="channel === 'email'" placeholder="name@qq.com">
            @error('email')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        </div>

        <fieldset>
            <legend class="label">인증 방법 · 验证方式</legend>
            <div class="grid grid-cols-2 gap-2 text-sm">
                <label class="flex items-center gap-2 rounded-2xl bg-paper px-3 py-2.5"><input type="radio" name="channel" value="sms" x-model="channel"> 문자 · 短信</label>
                <label class="flex items-center gap-2 rounded-2xl bg-paper px-3 py-2.5"><input type="radio" name="channel" value="email" x-model="channel"> 이메일 · 邮箱</label>
            </div>
            <p x-show="country === 'CN'" class="mt-2 text-xs text-mist">중국 번호는 해외 문자가 차단되는 경우가 많아 이메일 인증을 권해요. · 中国手机号可能收不到境外短信，建议使用邮箱验证（QQ邮箱、163邮箱均可）。</p>
        </fieldset>

        <div class="grid gap-2 sm:grid-cols-2">
            <div>
                <label for="password" class="label">비밀번호 · 密码</label>
                <input id="password" name="password" type="password" class="field" autocomplete="new-password" minlength="10" required>
            </div>
            <div>
                <label for="password_confirmation" class="label">비밀번호 확인</label>
                <input id="password_confirmation" name="password_confirmation" type="password" class="field" autocomplete="new-password" minlength="10" required>
            </div>
        </div>
        <p class="-mt-2 text-xs text-mist">10자 이상, 영문과 숫자를 섞어 주세요. 휴대폰 번호·이메일·흔한 비밀번호는 쓸 수 없어요. 다른 사이트와 같은 비밀번호는 피해 주세요.</p>
        @error('password')<p class="-mt-2 text-sm text-signal">{{ $message }}</p>@enderror

        <div class="space-y-2 rounded-2xl bg-paper p-4 text-sm">
            <label class="flex items-start gap-2"><input type="checkbox" name="agree_terms" value="1" class="mt-0.5" @checked(old('agree_terms')) required>
                <span><a href="{{ route('policy', 'terms') }}" target="_blank" class="row-link">이용약관</a>에 동의해요 (필수)</span></label>
            <label class="flex items-start gap-2"><input type="checkbox" name="agree_privacy" value="1" class="mt-0.5" @checked(old('agree_privacy')) required>
                <span><a href="{{ route('policy', 'privacy') }}" target="_blank" class="row-link">개인정보 수집·이용</a>에 동의해요 (필수)</span></label>
            <label x-show="country === 'CN'" class="flex items-start gap-2"><input type="checkbox" name="agree_overseas" value="1" class="mt-0.5" @checked(old('agree_overseas'))>
                <span>개인정보를 대한민국으로 이전하는 데 동의해요 (중국 회원 필수) · 我同意将个人信息传输至韩国境外处理（《个人信息保护法》）</span></label>
            @error('agree_terms')<p class="text-signal">{{ $message }}</p>@enderror
            @error('agree_privacy')<p class="text-signal">{{ $message }}</p>@enderror
            @error('agree_overseas')<p class="text-signal">{{ $message }}</p>@enderror
        </div>

        <button class="btn-ink w-full">인증번호 받기 · 获取验证码</button>
        <p class="text-center text-sm text-mist">이미 회원인가요? <a href="{{ route('member.login') }}" class="row-link text-ink">로그인</a></p>
    </form>
@endsection
