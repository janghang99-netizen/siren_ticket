<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
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
<body class="min-h-dvh" @hasSection('theme') style="--theme: @yield('theme')" @endif>
    <div class="mx-auto flex min-h-dvh max-w-md flex-col">
        <header class="sticky top-0 z-30 flex items-center justify-between gap-2 bg-white/40 px-4 py-3 backdrop-blur-xl">
            <a href="{{ route('home') }}" class="flex items-baseline gap-2" aria-label="{{ __('SIREN 홈') }}">
                <span class="font-display text-lg font-black tracking-tight">SIREN</span>
                <span class="hidden text-xs font-semibold text-mist min-[380px]:inline">{{ __('티켓 · 매장주문') }}</span>
            </a>
            <div class="flex items-center gap-3">
                {{-- 언어 고르기 (번역 파일: lang/*.json) --}}
                <nav class="flex items-center rounded-full bg-white/60 p-0.5 text-[11px] font-bold ring-1 ring-ink/10" aria-label="{{ __('언어') }}">
                    @foreach (\App\Http\Middleware\SetLocale::SUPPORTED as $code => $label)
                        <a href="{{ route('locale', $code) }}" lang="{{ str_replace('_', '-', $code) }}" hreflang="{{ str_replace('_', '-', $code) }}"
                           @class(['rounded-full px-2 py-1 transition', 'bg-ink text-white' => app()->getLocale() === $code, 'text-mist hover:text-ink' => app()->getLocale() !== $code])
                           @if (app()->getLocale() === $code) aria-current="true" @endif>{{ ['ko' => 'KO', 'zh_CN' => '中文', 'en' => 'EN'][$code] }}</a>
                    @endforeach
                </nav>
                @hasSection('header-action')
                    @yield('header-action')
                @else
                    @auth('member')
                        <a href="{{ route('member.account') }}" class="text-sm font-semibold text-stick">{{ __('내 계정') }}</a>
                    @else
                        <a href="{{ route('member.login', ['return' => request()->getRequestUri()]) }}" class="text-sm font-semibold text-stick">{{ __('로그인') }}</a>
                    @endauth
                @endif
            </div>
        </header>

        <main class="flex-1 px-4">
            @if (session('status'))
                <p role="status" class="mb-3 mt-2 rounded-2xl bg-white/80 px-4 py-3 text-sm font-bold ring-1 ring-ink/10 backdrop-blur">{{ __(session('status')) }}</p>
            @endif
            @error('rule')
                <p role="alert" class="mb-3 mt-2 rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal">{{ $message }}</p>
            @enderror
            @yield('content')
        </main>

        {{-- A3 통신판매중개자 고지 · E1 약관 --}}
        <footer class="px-4 pb-28 pt-12 text-[11px] leading-relaxed text-mist">
            <nav class="mb-2 flex flex-wrap gap-x-3 gap-y-1 font-semibold text-ink/70" aria-label="{{ __('약관') }}">
                <a href="{{ route('policy', 'terms') }}">{{ __('이용약관') }}</a>
                <a href="{{ route('policy', 'privacy') }}">{{ __('개인정보 처리방침') }}</a>
                <a href="{{ route('policy', 'refund') }}">{{ __('취소·환불 규정') }}</a>
                <a href="{{ route('partner.apply') }}">{{ __('매장 입점 신청') }}</a>
            </nav>
            <p>{{ __('SIREN은 공연 좌석 티켓을 직접 판매하며, 공연장 매장 상품에 대해서는 통신판매중개자로서 거래 당사자가 아닙니다. 매장 상품의 정보·품질·수령에 대한 책임은 각 판매자에게 있습니다.') }}</p>
            @if (app()->getLocale() !== 'ko')
                <p class="mt-2">{{ __('번역은 이해를 돕기 위한 것이며, 약관·환불 규정은 한국어 원문이 우선합니다.') }}</p>
            @endif
        </footer>

        @php($tab = trim($__env->yieldContent('tab')))
        <nav class="fixed inset-x-0 bottom-0 z-40 mx-auto max-w-md px-4 pb-[max(12px,env(safe-area-inset-bottom))]" aria-label="{{ __('주요 메뉴') }}">
            <div class="grid grid-cols-4 rounded-full bg-ink p-1.5 text-sm font-semibold text-paper/60 shadow-xl shadow-ink/20">
                @foreach ([['home', '공연', 'home'], ['tickets', '내 티켓', 'tickets'], ['orders', '주문내역', 'orders.index'], ['benefits', '혜택', 'benefits']] as [$key, $label, $route])
                    <a href="{{ route($route) }}"
                       @class(['rounded-full py-2.5 text-center transition', 'bg-paper text-ink' => $tab === $key, 'hover:text-paper' => $tab !== $key])
                       @if($tab === $key) aria-current="page" @endif>{{ __($label) }}</a>
                @endforeach
            </div>
        </nav>
    </div>
</body>
</html>
