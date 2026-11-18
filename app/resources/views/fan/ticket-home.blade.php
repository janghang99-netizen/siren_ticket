@extends('layouts.ticket')
@section('title', '공연 예매')
@section('genre', 'home')

@php
    $days = ['일', '월', '화', '수', '목', '금', '토'];
    // 예매 오픈 표시: 오늘 20:00 / 내일 11:00 / 11.20(금) 14:00
    $openLabel = function ($at) use ($days) {
        if ($at->isToday()) return '오늘 '.$at->format('H:i');
        if ($at->isTomorrow()) return '내일 '.$at->format('H:i');
        return $at->format('n.j').'('.$days[$at->dayOfWeek].') '.$at->format('H:i');
    };
    $icons = [
        'concert' => '<path d="M12 3v10.5a3.5 3.5 0 1 1-2-3.16V6l7-2v8.5a3.5 3.5 0 1 1-2-3.16"/>',
        'musical' => '<path d="M4 5c3 2 13 2 16 0v6a8 8 0 0 1-16 0Z"/><path d="M9 10h.01M15 10h.01M9.5 14.5c1.5 1 3.5 1 5 0"/>',
        'fanmeeting' => '<path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10Z"/>',
        'festival' => '<path d="M3 20 12 4l9 16Z"/><path d="M12 4v16M7.5 12h9"/>',
        'classic' => '<path d="M9 18V5l11-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="17" cy="16" r="3"/>',
        'theater' => '<path d="M4 4h16v6a8 8 0 0 1-16 0Z"/><path d="M8 9h.01M16 9h.01M8 13c2.5 2 5.5 2 8 0"/>',
        'sports' => '<circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'exhibition' => '<rect x="3" y="4" width="18" height="14" rx="2"/><path d="m3 15 5-5 4 4 3-3 6 6"/><circle cx="15.5" cy="8.5" r="1.5"/>',
    ];
    $rankGenres = collect(\App\Models\Production::GENRES)->filter(fn ($n, $k) => ($genreCounts[$k] ?? 0) > 0);
@endphp

@section('content')
    @if ($hero->isEmpty())
        <section class="tix-wrap mt-10">
            <div class="rounded-[28px] bg-paper px-6 py-20 text-center">
                <p class="font-display text-3xl font-black">곧 공연이 열려요</p>
                <p class="mt-2 text-sm text-mist">지금 예매할 수 있는 공연이 아직 없어요. 공연장 매장 주문은 언제든 할 수 있어요.</p>
                <a href="{{ route('shop.index') }}" class="btn-ink mt-6 inline-flex px-6">공연장 매장 둘러보기</a>
            </div>
        </section>
    @else

    {{-- ① 큰 배너 — 작품을 넘겨 보며 바로 예매 --}}
    <section class="tix-wrap mt-4 lg:mt-6" aria-roledescription="carousel" aria-label="추천 공연"
             x-data="{ i: 0, n: {{ $hero->count() }}, play: ! window.matchMedia('(prefers-reduced-motion: reduce)').matches, go(k) { this.i = (k + this.n) % this.n } }"
             x-init="setInterval(() => { if (play && n > 1) go(i + 1) }, 6000)">
        <div class="theme-keep relative h-[460px] overflow-hidden rounded-[28px] bg-ink text-white sm:h-[420px] lg:h-[460px]">
            @foreach ($hero as $k => $p)
                @php($ev = $p->nextEvent())
                <article class="absolute inset-0 transition-opacity duration-700" x-bind:class="i === {{ $k }} ? 'opacity-100' : 'pointer-events-none opacity-0'"
                         @if ($k > 0) style="opacity:0" x-bind:style="''" @endif
                         aria-roledescription="slide" aria-label="{{ $k + 1 }} / {{ $hero->count() }}" x-bind:aria-hidden="i !== {{ $k }}">
                    {{-- 배경: 가로 배너, 없으면 포스터를 크게 흐리게 --}}
                    @if ($p->bannerUrl())
                        <img src="{{ $p->bannerUrl() }}" alt="" class="absolute inset-0 h-full w-full object-cover" @if ($k > 0) loading="lazy" @endif>
                    @elseif ($p->posterUrl())
                        <img src="{{ $p->posterUrl() }}" alt="" class="absolute inset-0 h-full w-full scale-125 object-cover opacity-70 blur-2xl" @if ($k > 0) loading="lazy" @endif>
                    @else
                        <div class="poster-gen theme-keep absolute inset-0" style="--theme: {{ $p->theme_color }}"></div>
                    @endif
                    <div class="absolute inset-0 bg-[linear-gradient(90deg,rgba(23,19,43,.92)_0%,rgba(23,19,43,.7)_42%,rgba(23,19,43,.05)_75%)] max-sm:bg-[linear-gradient(0deg,rgba(23,19,43,.95)_0%,rgba(23,19,43,.6)_55%,rgba(23,19,43,.1)_100%)]"></div>

                    <div class="relative flex h-full items-end gap-10 p-6 sm:items-center sm:p-10 lg:px-16">
                        <div class="max-w-[560px] flex-1">
                            <p class="flex flex-wrap items-center gap-2 text-xs font-bold">
                                <span class="rounded-full bg-white/15 px-2.5 py-1 backdrop-blur">{{ $p->genreName() }}</span>
                                @if ($p->badge)<span class="rounded-full bg-pick px-2.5 py-1 text-ink">{{ $p->badge }}</span>@endif
                            </p>
                            <h2 class="mt-4 text-[30px] font-black leading-[1.08] tracking-tight [word-break:keep-all] sm:text-[40px] lg:text-[50px]">{{ $p->title }}</h2>
                            @if ($p->artist && ! str_contains(mb_strtoupper($p->title), mb_strtoupper($p->artist)))
                                <p class="mt-2 font-display text-sm font-bold tracking-wide text-white/75 lg:text-base">{{ $p->artist }}</p>
                            @endif
                            <p class="mt-5 text-sm font-medium text-white/80 lg:text-[15px]">{{ $p->venue }}</p>
                            <p class="num mt-1 text-sm text-white/80 lg:text-[15px]">{{ $p->period('Y.m.d') }}</p>
                            <div class="mt-6 flex flex-wrap items-center gap-2">
                                @if ($p->opensLater())
                                    <span class="inline-flex h-12 items-center rounded-full bg-white px-6 text-[15px] font-bold text-ink">{{ $openLabel($p->salesOpenAt()) }} 예매 오픈</span>
                                @elseif ($ev)
                                    <a href="{{ route('shows.show', $p) }}" class="inline-flex h-12 items-center gap-2 rounded-full bg-pick px-6 text-[15px] font-bold text-ink transition hover:brightness-95">
                                        예매하기 <span aria-hidden="true">→</span>
                                    </a>
                                @endif
                                @if ($p->lowestPrice())
                                    <span class="text-sm text-white/70"><span class="num font-bold text-white">{{ number_format($p->lowestPrice()) }}</span>원부터</span>
                                @endif
                            </div>
                        </div>
                        <a href="{{ route('shows.show', $p) }}" class="hidden w-[230px] shrink-0 rotate-[1.5deg] shadow-[0_30px_60px_-20px_rgba(0,0,0,.6)] transition hover:rotate-0 md:block lg:w-[260px]" tabindex="-1" aria-hidden="true">
                            @include('partials.poster', ['p' => $p, 'eager' => $k === 0])
                        </a>
                    </div>
                </article>
            @endforeach

            @if ($hero->count() > 1)
                <div class="absolute right-4 top-4 flex items-center gap-1.5 sm:bottom-6 sm:right-6 sm:top-auto">
                    <button type="button" @click="go(i - 1)" class="grid h-10 w-10 place-items-center rounded-full bg-white/15 backdrop-blur transition hover:bg-white hover:text-ink" aria-label="이전 공연">
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <span class="num min-w-[64px] rounded-full bg-black/30 px-3 py-2 text-center text-xs font-bold backdrop-blur" aria-live="polite">
                        <span x-text="String(i + 1).padStart(2, '0')">01</span><span class="text-white/50"> / {{ str_pad($hero->count(), 2, '0', STR_PAD_LEFT) }}</span>
                    </span>
                    <button type="button" @click="play = ! play" class="grid h-10 w-10 place-items-center rounded-full bg-white/15 backdrop-blur transition hover:bg-white hover:text-ink" x-bind:aria-label="play ? '자동 넘김 멈춤' : '자동 넘김 시작'">
                        <svg x-show="play" viewBox="0 0 24 24" class="h-4 w-4" fill="currentColor"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>
                        <svg x-show="! play" x-cloak viewBox="0 0 24 24" class="h-4 w-4" fill="currentColor"><path d="M7 5v14l12-7Z"/></svg>
                    </button>
                    <button type="button" @click="go(i + 1)" class="grid h-10 w-10 place-items-center rounded-full bg-white/15 backdrop-blur transition hover:bg-white hover:text-ink" aria-label="다음 공연">
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                    </button>
                </div>
            @endif
        </div>
    </section>

    {{-- ② 장르 --}}
    <nav class="tix-wrap mt-8 lg:mt-10" aria-label="장르 바로가기">
        <ul class="grid grid-cols-4 gap-x-2 gap-y-5 sm:grid-cols-8">
            @foreach (\App\Models\Production::GENRES as $key => $name)
                <li>
                    <a href="{{ route('shows.genre', $key) }}" class="group flex flex-col items-center gap-2 text-center">
                        <span class="grid h-14 w-14 place-items-center rounded-[20px] bg-paper text-ink transition group-hover:-translate-y-0.5 group-hover:bg-ink group-hover:text-white lg:h-[72px] lg:w-[72px] lg:rounded-3xl">
                            <svg viewBox="0 0 24 24" class="h-6 w-6 lg:h-7 lg:w-7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons[$key] !!}</svg>
                        </span>
                        <span class="text-[13px] font-semibold lg:text-sm">{{ $name }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    {{-- ③ 오픈 예정 --}}
    @if ($openSoon->isNotEmpty())
        <section class="tix-wrap mt-14 lg:mt-16" aria-labelledby="open-title">
            <div class="flex items-end justify-between">
                <h2 id="open-title" class="tix-h2">티켓 오픈 예정</h2>
            </div>
            <ul class="-mx-4 mt-5 flex snap-x scroll-px-4 gap-3 overflow-x-auto px-4 pb-2 [scrollbar-width:none] lg:mx-0 lg:grid lg:grid-cols-5 lg:gap-5 lg:overflow-visible lg:px-0">
                @foreach ($openSoon->take(10) as $p)
                    <li class="w-[150px] shrink-0 snap-start lg:w-auto">
                        <a href="{{ route('shows.show', $p) }}" class="group block">
                            @include('partials.poster', ['p' => $p])
                            <p class="theme-keep mt-3 inline-flex items-center gap-1.5 rounded-md bg-ink px-2 py-1 text-xs font-bold text-pick">
                                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                                {{ $openLabel($p->salesOpenAt()) }}
                            </p>
                            <p class="mt-2 line-clamp-2 text-[15px] font-bold leading-snug [word-break:keep-all]">{{ $p->title }}</p>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- ④ 장르별 랭킹 (최근 14일 예매 수) --}}
    <section class="tix-wrap mt-14 lg:mt-16" aria-labelledby="rank-title" x-data="{ g: 'all' }">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <h2 id="rank-title" class="tix-h2">지금 많이 예매하는 공연</h2>
        </div>
        <div class="-mx-4 mt-4 flex gap-1.5 overflow-x-auto px-4 [scrollbar-width:none] lg:mx-0 lg:px-0" role="tablist" aria-label="랭킹 장르">
            <button type="button" role="tab" @click="g = 'all'" x-bind:aria-selected="g === 'all'" class="shrink-0 rounded-full px-4 py-2 text-sm font-semibold ring-1 ring-ink/10 transition" x-bind:class="g === 'all' ? 'bg-ink text-white ring-ink' : 'text-ink/60 hover:text-ink'">전체</button>
            @foreach ($rankGenres as $key => $name)
                <button type="button" role="tab" @click="g = '{{ $key }}'" x-bind:aria-selected="g === '{{ $key }}'" class="shrink-0 rounded-full px-4 py-2 text-sm font-semibold text-ink/60 ring-1 ring-ink/10 transition hover:text-ink" x-bind:class="g === '{{ $key }}' && '!bg-ink !text-white !ring-ink'">{{ $name }}</button>
            @endforeach
        </div>
        @foreach (['all' => $ranked] + $rankGenres->map(fn ($n, $k) => $ranked->where('genre', $k)->values())->all() as $key => $list)
            <ol class="mt-6 grid grid-cols-2 gap-x-3 gap-y-8 sm:grid-cols-3 lg:grid-cols-5 lg:gap-x-5" role="tabpanel" x-show="g === '{{ $key }}'" @if ($key !== 'all') x-cloak @endif>
                @foreach ($list->take(10) as $n => $p)
                    @php($ev = $p->nextEvent())
                    <li>
                        <a href="{{ route('shows.show', $p) }}" class="group block">
                            <div class="relative">
                                @include('partials.poster', ['p' => $p])
                                <span @class(['rank-no pointer-events-none absolute bottom-2 left-3 text-[56px] lg:text-[64px]', 'is-top' => $n < 3])>{{ $n + 1 }}</span>
                            </div>
                            <p class="mt-3 line-clamp-2 text-[15px] font-bold leading-snug [word-break:keep-all]">{{ $p->title }}</p>
                            <p class="mt-1 truncate text-[13px] text-mist">{{ $p->venue }}</p>
                            <p class="num text-[13px] text-mist">{{ $p->period() }}</p>
                            @if ($p->opensLater())
                                <p class="mt-1.5 text-[13px] font-bold text-stick">{{ $openLabel($p->salesOpenAt()) }} 오픈</p>
                            @elseif ($p->lowestPrice())
                                <p class="mt-1.5 text-[13px]"><span class="num font-bold">{{ number_format($p->lowestPrice()) }}</span>원~</p>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ol>
        @endforeach
    </section>

    {{-- ⑤ 모든 티켓은 블록체인 티켓 --}}
    @include('partials.blockchain-band')

    {{-- ⑥ 다담다만의 것: 공연장 매장 미리 주문 --}}
    <section class="tix-wrap mt-16" aria-labelledby="pre-title">
        <div class="theme-keep relative overflow-hidden rounded-[28px] bg-ink px-6 py-10 text-white sm:px-10 lg:flex lg:items-center lg:justify-between lg:gap-10 lg:px-14 lg:py-12">
            <div class="pointer-events-none absolute -right-10 -top-24 h-72 w-72 rounded-full bg-pick/25 blur-3xl" aria-hidden="true"></div>
            <div class="relative">
                <p class="font-display text-xs font-bold tracking-[.2em] text-pick">PRE-ORDER</p>
                <h2 id="pre-title" class="mt-3 text-[26px] font-black leading-tight tracking-tight lg:text-[34px]">공연 전·인터미션,<br> 줄 서지 말고 미리 주문</h2>
                <p class="mt-3 max-w-md text-sm text-white/70">티켓을 예매했다면 공연장 매장 음료·간식·MD를 자리에서 주문하세요. 준비되면 번호가 노랗게 바뀌어요.</p>
            </div>
            <div class="relative mt-8 flex flex-col gap-4 sm:flex-row sm:items-center lg:mt-0">
                <ol class="flex flex-wrap gap-2 whitespace-nowrap text-xs font-semibold">
                    @foreach (['자리에서 주문', '번호가 노랗게', '매장에서 받기'] as $s => $label)
                        <li class="flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-2"><span @class(['num grid h-5 w-5 place-items-center rounded-full text-[10px]', 'bg-pick text-ink' => $s === 1, 'bg-white text-ink' => $s !== 1])>{{ $s + 1 }}</span>{{ $label }}</li>
                    @endforeach
                </ol>
                <a href="{{ route('shop.index') }}" class="inline-flex h-12 shrink-0 items-center justify-center gap-2 rounded-full bg-pick px-6 text-[15px] font-bold text-ink transition hover:brightness-95">매장 {{ $storeCount }}곳 보기 <span aria-hidden="true">→</span></a>
            </div>
        </div>
    </section>

    {{-- ⑥ 공연장 --}}
    @if ($venues->isNotEmpty())
        <section class="tix-wrap mt-16" aria-labelledby="venue-title">
            <h2 id="venue-title" class="tix-h2">공연장</h2>
            <ul class="mt-5 flex flex-wrap gap-2">
                @foreach ($venues as $name => $count)
                    <li>
                        <a href="{{ route('shows.search', ['q' => $name]) }}" class="inline-flex items-center gap-2 rounded-full bg-paper px-4 py-2.5 text-sm font-semibold transition hover:bg-ink hover:text-white">
                            <svg viewBox="0 0 24 24" class="h-4 w-4 text-mist" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21Z"/><circle cx="12" cy="9.5" r="2.5"/></svg>
                            {{ $name }} <span class="num text-xs text-mist">{{ $count }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
    @endif
@endsection
