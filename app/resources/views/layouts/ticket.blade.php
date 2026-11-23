<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    @if (config('siren.noindex'))<meta name="robots" content="noindex, nofollow, noarchive">@endif
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0E0C15">
    @include('partials.theme-init')
    <title>@yield('title', $site['brand_name']) · {{ $site['brand_name'] }} 티켓</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- 공연 예매 포털 레이아웃 (공연 기능을 켰을 때 메인·장르·검색). 넓은 화면은 가운데 1200px 열, 휴대폰은 좌우 16px --}}
<body class="tix min-h-dvh bg-white text-ink">
    @php($genre = trim($__env->yieldContent('genre')))
    <header class="sticky top-0 z-40 border-b border-ink/[.06] bg-white/90 backdrop-blur-xl">
        <div class="tix-wrap flex h-16 items-center gap-4 lg:h-[72px] lg:gap-8">
            <a href="{{ route('home') }}" class="flex shrink-0 items-center gap-2" aria-label="{{ $site['brand_name'] }} 티켓 홈">
                @include('partials.brand-logo', ['h' => 46])
                <span class="theme-keep rounded-md bg-ink px-1.5 py-0.5 font-display text-[10px] font-bold tracking-wider text-pick ring-1 ring-white/10 lg:text-[11px]">TICKET</span>
            </a>

            <form action="{{ route('shows.search') }}" method="get" role="search" class="relative hidden max-w-[440px] flex-1 md:block">
                <label for="tix-q" class="sr-only">공연 검색</label>
                <input id="tix-q" name="q" value="{{ request('q') }}" placeholder="아티스트, 공연, 공연장 검색" autocomplete="off"
                       class="h-11 w-full rounded-full border border-ink/10 bg-paper/70 pl-5 pr-12 text-[15px] outline-none transition placeholder:text-mist focus:border-ink/40 focus:bg-white">
                <button class="absolute right-1.5 top-1.5 grid h-8 w-8 place-items-center rounded-full text-ink/70 hover:bg-ink hover:text-white" aria-label="검색">
                    <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                </button>
            </form>

            <nav class="ml-auto flex items-center gap-1 text-sm font-semibold sm:gap-2" aria-label="내 메뉴">
                @include('partials.theme-toggle')
                <a href="{{ route('shows.search') }}" class="grid h-10 w-10 place-items-center rounded-full hover:bg-paper md:hidden" aria-label="검색">
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                </a>
                <a href="{{ route('tickets') }}" class="hidden items-center gap-1.5 rounded-full px-3 py-2 hover:bg-paper sm:flex">
                    <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2v-2a2 2 0 0 0 0-4Z"/><path d="M9 6v12" stroke-dasharray="2 2.5"/></svg>
                    내 티켓
                </a>
                <a href="{{ route('shop.index') }}" class="hidden items-center gap-1.5 rounded-full px-3 py-2 hover:bg-paper lg:flex">
                    <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9h16l-1.2 10.2a2 2 0 0 1-2 1.8H7.2a2 2 0 0 1-2-1.8Z"/><path d="M9 9V7a3 3 0 0 1 6 0v2"/></svg>
                    매장 주문
                </a>
                @auth('member')
                    <a href="{{ route('member.account') }}" class="rounded-full bg-ink px-4 py-2 text-white">내 계정</a>
                @else
                    <a href="{{ route('member.login', ['return' => request()->getRequestUri()]) }}" class="rounded-full bg-ink px-4 py-2 text-white">로그인</a>
                @endauth
            </nav>
        </div>

        {{-- 장르 줄 — 휴대폰에선 옆으로 밀어서 --}}
        <div class="tix-wrap">
            <nav class="-mx-4 flex gap-1 overflow-x-auto px-4 pb-2 text-[15px] font-semibold [scrollbar-width:none] lg:mx-0 lg:gap-2 lg:px-0" aria-label="장르">
                <a href="{{ route('home') }}" @class(['tix-tab', 'is-on' => $genre === 'home']) @if($genre === 'home') aria-current="page" @endif>홈</a>
                @foreach (\App\Models\Production::GENRES as $key => $name)
                    <a href="{{ route('shows.genre', $key) }}" @class(['tix-tab', 'is-on' => $genre === $key]) @if($genre === $key) aria-current="page" @endif>{{ $name }}</a>
                @endforeach
                <span class="mx-1 my-2 hidden w-px shrink-0 bg-ink/10 lg:block" aria-hidden="true"></span>
                <a href="{{ route('shop.index') }}" class="tix-tab !text-stick">공연장 매장 주문</a>
            </nav>
        </div>
    </header>

    <main class="pb-28 lg:pb-0">
        @if (session('status'))
            <div class="tix-wrap"><p role="status" class="mt-4 rounded-2xl bg-paper px-4 py-3 text-sm font-bold">{{ session('status') }}</p></div>
        @endif
        @yield('content')
    </main>

    <footer class="mt-20 border-t border-ink/[.06] bg-paper/60">
        <div class="tix-wrap grid gap-6 py-10 text-xs leading-relaxed text-mist lg:grid-cols-[1fr_auto]">
            <div>
                <div class="opacity-80">@include('partials.brand-logo', ['h' => 56])</div>
                <nav class="mt-3 flex flex-wrap gap-x-4 gap-y-1 font-semibold text-ink/70" aria-label="약관">
                    <a href="{{ route('policy', 'terms') }}">이용약관</a>
                    <a href="{{ route('policy', 'privacy') }}" class="text-ink">개인정보 처리방침</a>
                    <a href="{{ route('policy', 'refund') }}">취소·환불 규정</a>
                    <a href="{{ route('inquiries.index') }}">1:1 문의</a>
                    <a href="{{ route('partner.apply') }}">매장 입점 신청</a>
                </nav>
                @if ($site['company_info_visible'] === '1')
                <dl class="mt-4 flex flex-wrap gap-x-4 gap-y-0.5">
                    <div class="flex gap-1.5"><dt>상호</dt><dd class="text-ink/70">{{ $site['company_name'] }}</dd></div>
                    @if ($site['company_ceo'])<div class="flex gap-1.5"><dt>대표</dt><dd class="text-ink/70">{{ $site['company_ceo'] }}</dd></div>@endif
                    @if ($site['company_reg_no'])<div class="flex gap-1.5"><dt>사업자등록번호</dt><dd class="num text-ink/70">{{ $site['company_reg_no'] }}</dd></div>@endif
                    @if ($site['company_mail_order_no'])<div class="flex gap-1.5"><dt>통신판매업신고</dt><dd class="num text-ink/70">{{ $site['company_mail_order_no'] }}</dd></div>@endif
                    @if ($site['company_address'])<div class="flex gap-1.5"><dt>주소</dt><dd class="text-ink/70">{{ $site['company_address'] }}</dd></div>@endif
                    @if ($site['company_phone'])<div class="flex gap-1.5"><dt>고객센터</dt><dd class="num text-ink/70">{{ $site['company_phone'] }}</dd></div>@endif
                    @if ($site['company_email'])<div class="flex gap-1.5"><dt>이메일</dt><dd class="text-ink/70">{{ $site['company_email'] }}</dd></div>@endif
                </dl>
                @endif
                @if ($site['company_info_visible'] === '1')
                    <p class="mt-3 max-w-3xl">{{ $site['brand_name'] }}은(는) 공연 좌석 티켓을 직접 판매하며, 매장 상품에 대해서는 통신판매중개자로서 거래 당사자가 아닙니다. 매장 상품의 정보·품질·수령에 대한 책임은 각 판매자에게 있습니다.</p>
                @else
                    <p class="mt-3 font-semibold text-ink/70">이 사이트는 샘플 사이트입니다.</p>
                @endif
            </div>
            <nav class="translation-links notranslate flex h-fit items-center self-start rounded-full bg-white p-0.5 text-[11px] font-bold ring-1 ring-ink/10" translate="no" aria-label="언어 Language">
                @foreach (['ko' => 'KO', 'en' => 'EN', 'ja' => '日本語', 'zh-CN' => '中文'] as $code => $label)
                    <a href="#" data-lang="{{ $code }}" lang="{{ $code }}" @class(['rounded-full px-2.5 py-1 transition hover:text-ink', 'bg-ink text-white' => $code === 'ko', 'text-mist' => $code !== 'ko'])>{{ $label }}</a>
                @endforeach
            </nav>
        </div>
        <p id="translate-notice" class="tix-wrap pb-6 text-[11px] text-mist" hidden>번역은 이해를 돕기 위한 것이며, 약관·환불 규정은 한국어 원문이 우선합니다.</p>
    </footer>

    {{-- 휴대폰 하단 메뉴 (넓은 화면은 상단 메뉴로) --}}
    <nav class="fixed inset-x-0 bottom-0 z-40 mx-auto max-w-md px-4 pb-[max(12px,env(safe-area-inset-bottom))] lg:hidden" aria-label="주요 메뉴">
        <div class="grid grid-cols-4 rounded-full bg-ink p-1.5 text-[13px] font-semibold text-paper/60 shadow-xl shadow-ink/20">
            @foreach ([['home', '홈', 'home'], ['order', '매장주문', 'order.entry'], ['tickets', '내 티켓', 'tickets'], ['orders', '주문내역', 'orders.index']] as [$key, $label, $route])
                <a href="{{ route($route) }}" @class(['whitespace-nowrap rounded-full py-2.5 text-center transition', 'bg-paper text-ink' => $key === 'home', 'hover:text-paper' => $key !== 'home'])>{{ $label }}</a>
            @endforeach
        </div>
    </nav>
    @include('partials.translate')
</body>
</html>
