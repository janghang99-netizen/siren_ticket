<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') · SIREN {{ auth()->user()->isSuperAdmin() ? '운영' : '파트너' }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    $user = auth()->user();
    $nav = $user->isSuperAdmin()
        ? [
            ['admin.events.index', '공연 일정', 'admin.events.*'],
            ['admin.seat-maps.index', '공연장 좌석표', 'admin.seat-maps.*'],
            ['admin.tickets.index', '티켓 판매', 'admin.tickets.*'],
            ['admin.check-in', '입장 검표', 'admin.check-in*'],
            ['admin.vendors.index', '입점업체', 'admin.vendors.*'],
            ['admin.stores.index', '매장', 'admin.stores.*'],
            ['admin.orders.index', '전체 주문', 'admin.orders.*'],
            ['admin.settlements.index', '정산', 'admin.settlements.*'],
            ['admin.coupons.index', '쿠폰·스탬프', 'admin.coupons.*'],
            ['admin.customers.index', '고객·마일리지', 'admin.customers.*'],
            ['admin.ledger', '혜택 원장', 'admin.ledger*'],
            ['admin.translations', '번역', 'admin.translations*'],
            ['admin.settings', '수수료·정산 설정', 'admin.settings*'],
            ['admin.audit', '감사 로그', 'admin.audit'],
        ]
        : [
            ['partner.board', '주문보드', 'partner.board'],
            ['partner.pos.screen', '현장판매', 'partner.pos.screen'],
            ['partner.terminals', '카드 단말기', 'partner.terminals*'],
            ['partner.products', '상품', 'partner.products*'],
            ['partner.production', '생산관리', 'partner.production*'],
            ['partner.inventory', '재고 원장', 'partner.inventory*'],
            ['partner.sales', '판매 내역', 'partner.sales|partner.orders.*'],
            ['partner.cancels', '취소 내역', 'partner.cancels'],
            ['partner.coupons', '쿠폰', 'partner.coupons*'],
            ['partner.stamp', '스탬프', 'partner.stamp*'],
            ['partner.settlements', '정산 내역', 'partner.settlements*'],
        ];
@endphp
<body class="min-h-dvh">
<div class="lg:grid lg:min-h-dvh lg:grid-cols-[232px_1fr]">
    <aside class="bg-ink text-paper lg:sticky lg:top-0 lg:h-dvh lg:overflow-y-auto">
        <div class="flex items-center justify-between px-5 py-4 lg:block lg:py-6">
            <div>
                <p class="font-display text-lg font-black">SIREN</p>
                <p class="text-xs font-semibold text-paper/50">{{ $user->isSuperAdmin() ? '운영 콘솔' : ($user->vendor->name ?? '파트너') }}</p>
            </div>
            <form method="POST" action="{{ route('logout') }}" class="lg:hidden">@csrf<button class="text-sm text-paper/60">로그아웃</button></form>
        </div>

        @if (! $user->isSuperAdmin() && isset($stores) && $stores->count() > 0)
            {{-- 한 업체가 여러 매장: 주문보드·상품은 고른 매장 기준 --}}
            <div class="px-3 pb-3 lg:pb-5">
                <p class="px-2 pb-2 text-[11px] font-bold tracking-[.18em] text-paper/40">운영 매장</p>
                <div class="flex gap-1.5 overflow-x-auto lg:flex-col">
                    @foreach ($stores as $s)
                        <form method="POST" action="{{ route('partner.stores.switch', $s->id) }}">
                            @csrf
                            <button @class(['w-full whitespace-nowrap rounded-xl px-3 py-2 text-left text-sm font-semibold transition',
                                            'bg-white text-ink' => $s->id === $store->id,
                                            'text-paper/70 hover:bg-paper/10' => $s->id !== $store->id])
                                    @if ($s->id === $store->id) aria-current="true" @endif>
                                {{ $s->name }}
                                @unless ($s->is_open)<span class="ml-1 text-xs opacity-60">영업 종료</span>@endunless
                            </button>
                        </form>
                    @endforeach
                </div>
            </div>
        @endif

        <nav class="flex gap-1 overflow-x-auto border-t border-paper/10 px-3 py-3 lg:flex-col" aria-label="관리 메뉴">
            @foreach ($nav as [$route, $label, $pattern])
                <a href="{{ route($route) }}"
                   @class(['whitespace-nowrap rounded-xl px-3 py-2.5 text-sm font-semibold transition',
                           'bg-paper text-ink' => request()->routeIs(...explode('|', $pattern)),
                           'text-paper/70 hover:bg-paper/10 hover:text-paper' => ! request()->routeIs(...explode('|', $pattern))])
                   @if (request()->routeIs(...explode('|', $pattern))) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>

        <div class="hidden px-5 py-6 lg:block">
            <p class="truncate text-xs text-paper/50">{{ $user->email }}</p>
            <form method="POST" action="{{ route('logout') }}" class="mt-2">@csrf<button class="text-sm font-semibold text-paper/70 hover:text-paper">로그아웃</button></form>
        </div>
    </aside>

    <main class="min-w-0 px-5 py-6 lg:px-10 lg:py-8">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                @hasSection('eyebrow')<p class="eyebrow">@yield('eyebrow')</p>@endif
                <h1 class="mt-1 text-2xl font-black">@yield('title')</h1>
            </div>
            <div class="flex flex-wrap gap-2">@yield('actions')</div>
        </div>

        @if (session('status'))
            <p role="status" class="mb-5 rounded-2xl bg-white/80 px-4 py-3 text-sm font-semibold ring-1 ring-ink/10 backdrop-blur">{{ session('status') }}</p>
        @endif
        @error('rule')
            <p role="alert" class="mb-5 rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal">{{ $message }}</p>
        @enderror

        @yield('content')
    </main>
</div>
</body>
</html>
