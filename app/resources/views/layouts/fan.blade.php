<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    @if (config('siren.noindex'))<meta name="robots" content="noindex, nofollow, noarchive">@endif
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0E0C15">
    @include('partials.theme-init')
    <title>@yield('title', $site['brand_name']) · {{ $site['brand_name'] }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh" @hasSection('theme') style="--theme: @yield('theme')" @endif>
    <div class="flex min-h-dvh flex-col">
        {{-- 상단 바: 배경 띠는 화면 전체 폭, 내용은 본문과 같은 열(max-w-md · 좌우 16px)에 맞춘다. 높이 56px(h-14) — 아래 고정 줄은 top-14 에 붙인다 --}}
        <header class="sticky top-0 z-30 border-b border-ink/5 bg-white/55 backdrop-blur-xl">
            <div class="mx-auto flex h-14 max-w-md items-center justify-between gap-2 px-4">
            <a href="{{ route('home') }}" class="flex items-center gap-2" aria-label="{{ $site['brand_name'] }} 홈">
                @include('partials.brand-logo', ['h' => 40])
                <span class="hidden text-xs font-semibold text-mist min-[400px]:inline">티켓 · 매장주문</span>
            </a>
            <div class="flex items-center gap-2">
                @include('partials.theme-toggle')
                {{-- 언어 고르기 — 구글 번역 위젯 (partials/translate) --}}
                <nav class="translation-links notranslate flex items-center rounded-full bg-white/60 p-0.5 text-[11px] font-bold ring-1 ring-ink/10" translate="no" aria-label="언어 Language">
                    @foreach (['ko' => 'KO', 'en' => 'EN', 'ja' => '日本語', 'zh-CN' => '中文'] as $code => $label)
                        <a href="#" data-lang="{{ $code }}" lang="{{ $code }}"
                           @class(['rounded-full px-2 py-1 transition hover:text-ink', 'bg-ink text-white' => $code === 'ko', 'text-mist' => $code !== 'ko'])>{{ $label }}</a>
                    @endforeach
                </nav>
                @hasSection('header-action')
                    @yield('header-action')
                @else
                    @auth('member')
                        <a href="{{ route('member.account') }}" class="text-sm font-semibold text-stick">내 계정</a>
                    @else
                        <a href="{{ route('member.login', ['return' => request()->getRequestUri()]) }}" class="text-sm font-semibold text-stick">로그인</a>
                    @endauth
                @endif
            </div>
            </div>
        </header>

        {{-- 본문은 상단 바에서 24px(pt-4 + 각 화면 첫 요소 mt-2) 띄워 시작 --}}
        <main class="mx-auto w-full max-w-md flex-1 px-4 pt-4">
            @if (session('status'))
                <p role="status" class="mb-3 mt-2 rounded-2xl bg-white/80 px-4 py-3 text-sm font-bold ring-1 ring-ink/10 backdrop-blur">{{ session('status') }}</p>
            @endif
            @error('rule')
                <p role="alert" class="mb-3 mt-2 rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal">{{ $message }}</p>
            @enderror
            @yield('content')
        </main>

        {{-- A3 통신판매중개자 고지 · E1 약관 --}}
        <footer class="mx-auto w-full max-w-md px-4 pb-28 pt-12 text-[11px] leading-relaxed text-mist">
            <nav class="mb-2 flex flex-wrap gap-x-3 gap-y-1 font-semibold text-ink/70" aria-label="약관">
                <a href="{{ route('policy', 'terms') }}">이용약관</a>
                <a href="{{ route('policy', 'privacy') }}">개인정보 처리방침</a>
                <a href="{{ route('policy', 'refund') }}">취소·환불 규정</a>
                <a href="{{ route('inquiries.index') }}">1:1 문의</a>
                <a href="{{ route('partner.apply') }}">매장 입점 신청</a>
            </nav>
            {{-- 운영사 사업자 정보 (원본 다담다 하단과 같은 항목 — 운영사 설정에서 고친다) --}}
            <details class="mb-2">
                <summary class="cursor-pointer font-semibold text-ink/70">{{ $site['company_name'] }} 사업자 정보</summary>
                <dl class="mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
                    <dt>상호</dt><dd>{{ $site['company_name'] }}</dd>
                    @if ($site['company_ceo'])<dt>대표</dt><dd>{{ $site['company_ceo'] }}</dd>@endif
                    @if ($site['company_reg_no'])<dt>사업자등록번호</dt><dd class="num">{{ $site['company_reg_no'] }}</dd>@endif
                    @if ($site['company_mail_order_no'])<dt>통신판매업신고</dt><dd class="num">{{ $site['company_mail_order_no'] }}</dd>@endif
                    @if ($site['company_address'])<dt>주소</dt><dd>{{ $site['company_address'] }}</dd>@endif
                    @if ($site['company_phone'])<dt>고객센터</dt><dd class="num">{{ $site['company_phone'] }}</dd>@endif
                    @if ($site['company_email'])<dt>이메일</dt><dd>{{ $site['company_email'] }}</dd>@endif
                </dl>
            </details>
            <p>@if ($ticketing){{ $site['brand_name'] }}은(는) 공연 좌석 티켓을 직접 판매하며, 매장 상품에 대해서는 @else{{ $site['company_name'] }}은(는) @endif통신판매중개자로서 거래 당사자가 아닙니다. 매장 상품의 정보·품질·수령에 대한 책임은 각 판매자에게 있습니다.</p>
            <p id="translate-notice" class="mt-2" hidden>번역은 이해를 돕기 위한 것이며, 약관·환불 규정은 한국어 원문이 우선합니다.</p>
        </footer>

        @php($tab = trim($__env->yieldContent('tab')))
        <nav class="fixed inset-x-0 bottom-0 z-40 mx-auto max-w-md px-4 pb-[max(12px,env(safe-area-inset-bottom))]" aria-label="주요 메뉴">
            @php($tabs = array_values(array_filter([['home', '홈', 'home'], ['order', '매장주문', 'order.entry'], $ticketing ? ['tickets', '내 티켓', 'tickets'] : null, ['orders', '주문내역', 'orders.index'], ['benefits', '혜택', 'benefits']])))
            <div @class(['grid rounded-full bg-ink p-1.5 text-[13px] font-semibold text-paper/60 shadow-xl shadow-ink/20', 'grid-cols-5' => count($tabs) === 5, 'grid-cols-4' => count($tabs) === 4])>
                @foreach ($tabs as [$key, $label, $route])
                    <a href="{{ route($route) }}"
                       @class(['whitespace-nowrap rounded-full py-2.5 text-center transition', 'bg-paper text-ink' => $tab === $key, 'hover:text-paper' => $tab !== $key])
                       @if($tab === $key) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </div>
        </nav>
    </div>
    @include('partials.translate')
</body>
</html>
