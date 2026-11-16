<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>비밀번호 설정 · {{ $site['brand_name'] }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-dvh place-items-center px-4">
    <form method="POST" action="{{ route('staff.password.update') }}" class="w-full max-w-sm rounded-[28px] bg-white/75 p-7 shadow-[0_30px_80px_-40px_rgba(23,19,43,.4)] ring-1 ring-white backdrop-blur-xl">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <p class="font-display text-xl font-black">{{ $site['brand_name'] }}</p>
        <h1 class="mt-1 text-lg font-bold">새 비밀번호 설정</h1>
        <p class="mt-1 text-sm text-mist">영문과 숫자를 섞어 10자 이상으로 정해 주세요.</p>
        <div class="mt-6 space-y-4">
            <div>
                <label for="email" class="label">이메일</label>
                <input id="email" name="email" type="email" value="{{ old('email', $email) }}" class="field" autocomplete="username" required>
            </div>
            <div>
                <label for="password" class="label">새 비밀번호</label>
                <input id="password" name="password" type="password" class="field" autocomplete="new-password" minlength="10" required autofocus>
            </div>
            <div>
                <label for="password_confirmation" class="label">새 비밀번호 확인</label>
                <input id="password_confirmation" name="password_confirmation" type="password" class="field" autocomplete="new-password" required>
            </div>
            @if ($errors->any())<p role="alert" class="text-sm font-semibold text-signal">{{ $errors->first() }}</p>@endif
        </div>
        <button class="btn-ink mt-6 w-full">비밀번호 설정</button>
    </form>
</body>
</html>
