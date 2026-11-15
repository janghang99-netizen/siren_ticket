<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#F1EEF8">
    <title>@yield('title', 'SIREN') · SIREN</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh">
    <div class="mx-auto flex min-h-dvh max-w-md flex-col">
        <header class="sticky top-0 z-30 flex items-center justify-between bg-paper/85 px-4 py-3 backdrop-blur">
            <a href="{{ route('home') }}" class="flex items-baseline gap-2" aria-label="SIREN 홈">
                <span class="font-display text-lg font-black tracking-tight">SIREN</span>
                <span class="text-xs font-semibold text-mist">티켓 · 매장주문</span>
            </a>
            @yield('header-action')
        </header>

        <main class="flex-1 px-4 pb-28">
            @yield('content')
        </main>

        @php($tab = trim($__env->yieldContent('tab')))
        <nav class="fixed inset-x-0 bottom-0 z-40 mx-auto max-w-md px-4 pb-[max(12px,env(safe-area-inset-bottom))]" aria-label="주요 메뉴">
            <div class="grid grid-cols-3 rounded-full bg-ink p-1.5 text-sm font-semibold text-paper/60 shadow-xl shadow-ink/20">
                @foreach ([['home', '공연', 'home'], ['tickets', '내 티켓', 'tickets'], ['orders', '주문내역', 'orders.index']] as [$key, $label, $route])
                    <a href="{{ route($route) }}"
                       @class(['rounded-full py-2.5 text-center transition', 'bg-paper text-ink' => $tab === $key, 'hover:text-paper' => $tab !== $key])
                       @if($tab === $key) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </div>
        </nav>
    </div>
</body>
</html>
