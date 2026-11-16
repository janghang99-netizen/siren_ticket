<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>비밀번호 찾기 · {{ $site['brand_name'] }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-dvh place-items-center px-4">
    <form method="POST" action="{{ route('staff.password.send') }}" class="w-full max-w-sm rounded-[28px] bg-white/75 p-7 shadow-[0_30px_80px_-40px_rgba(23,19,43,.4)] ring-1 ring-white backdrop-blur-xl">
        @csrf
        <p class="font-display text-xl font-black">{{ $site['brand_name'] }}</p>
        <h1 class="mt-1 text-lg font-bold">관리 화면 비밀번호 찾기</h1>
        <p class="mt-1 text-sm text-mist">로그인 이메일을 적으면 비밀번호 설정 링크를 메일로 보내 드려요.</p>
        @if (session('status'))
            <p role="status" class="mt-4 rounded-2xl bg-stick/10 px-4 py-3 text-sm font-semibold text-stick">{{ session('status') }}</p>
        @endif
        <div class="mt-6">
            <label for="email" class="label">이메일</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" class="field" autocomplete="username" required autofocus>
            @error('email')<p role="alert" class="mt-1 text-sm font-semibold text-signal">{{ $message }}</p>@enderror
        </div>
        <button class="btn-ink mt-6 w-full">설정 메일 받기</button>
        <a href="{{ route('login') }}" class="mt-3 block text-center text-sm font-semibold text-stick">로그인으로</a>
    </form>
</body>
</html>
