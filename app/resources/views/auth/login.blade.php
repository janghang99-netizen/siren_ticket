<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>관리 로그인 · {{ $site['brand_name'] }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-dvh place-items-center px-4">
    <form method="POST" action="{{ route('login') }}" class="w-full max-w-sm rounded-[28px] bg-white/75 p-7 shadow-[0_30px_80px_-40px_rgba(23,19,43,.4)] ring-1 ring-white backdrop-blur-xl">
        @csrf
        <p class="font-display text-xl font-black">{{ $site['brand_name'] }}</p>
        <h1 class="mt-1 text-lg font-bold">관리 화면 로그인</h1>
        <p class="mt-1 text-sm text-mist">운영사는 공연·티켓·정산을, 입점업체는 우리 매장 주문과 상품을 관리해요.</p>
        @if ($current)
            <p role="status" class="mt-4 rounded-2xl bg-ink/5 px-4 py-3 text-sm">
                지금 <b>{{ $current->email }}</b>({{ $current->isSuperAdmin() ? '운영사' : '입점업체' }})로 로그인해 있어요.
                다른 계정으로 로그인하면 바뀌어요.
                <a href="{{ \App\Http\Controllers\AuthController::home($current) }}" class="mt-1 block font-semibold text-stick">지금 계정으로 계속 →</a>
            </p>
        @endif

        <div class="mt-6 space-y-4">
            <div>
                <label for="email" class="label">이메일</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" class="field" autocomplete="username" required autofocus>
            </div>
            <div>
                <label for="password" class="label">비밀번호</label>
                <input id="password" name="password" type="password" class="field" autocomplete="current-password" required>
            </div>
            @error('email')<p role="alert" class="text-sm font-semibold text-signal">{{ $message }}</p>@enderror
        </div>
        <button class="btn-ink mt-6 w-full">로그인</button>
    </form>
</body>
</html>
