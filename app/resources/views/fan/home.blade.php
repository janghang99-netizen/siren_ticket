@extends('layouts.fan')
@section('title', '홈')
@section('tab', 'home')
@if ($headliner) @section('theme', $headliner->theme_color) @endif

@php($days = ['일', '월', '화', '수', '목', '금', '토'])
@php($slotNames = ['pre' => '공연 전', 'intermission' => '인터미션', 'post' => '공연 후'])

@section('content')
    @if ($headliner)
        @php($e = $headliner)

        {{-- ① 오늘의 공연 — 좌석 예매 · 매장 주문 두 갈래 --}}
        <section class="fandom-soft relative mt-2 overflow-hidden rounded-[28px] shadow-[0_24px_60px_-28px_rgba(23,19,43,.35)] animate-rise">
            <div class="relative px-5 pt-5">
                <div class="flex items-center justify-between">
                    <span class="eyebrow !text-ink/50">{{ $e->venue }}</span>
                    @if ($e->salesOpen())
                        <span class="chip bg-ink text-white">예매 중</span>
                    @else
                        <span class="chip bg-white/70 text-ink">예매 오픈 {{ $e->sales_open_at?->format('n.j H:i') }}</span>
                    @endif
                </div>
                <h1 class="mt-4 text-[40px] font-black leading-[1.02] tracking-tight">{{ $e->artist }}</h1>
                <p class="mt-2 text-base font-medium text-ink/65">{{ $e->name }}</p>
                <p class="num mt-4 text-2xl font-bold">
                    {{ $e->starts_at->format('m.d') }} <span class="text-ink/40">{{ $days[$e->starts_at->dayOfWeek] }}</span> {{ $e->starts_at->format('H:i') }}
                </p>
            </div>

            <div class="relative -mb-2 px-10 pt-1">
                @include('partials.bowl', ['event' => $e, 'class' => 'w-full'])
            </div>

            <div class="relative grid grid-cols-2 gap-2 p-3">
                <a href="{{ route('concerts.show', $e) }}" class="group rounded-[20px] bg-ink px-4 py-3.5 text-white transition active:scale-[.98]">
                    <span class="block text-[11px] font-bold tracking-wide text-white/55">좌석 티켓</span>
                    <span class="mt-0.5 flex items-center justify-between text-base font-bold">좌석 고르기 <span aria-hidden="true" class="transition group-hover:translate-x-0.5">→</span></span>
                    <span class="num mt-1 block text-xs font-medium text-white/60">{{ number_format($e->seatsLeft()) }}<span class="font-sans">석 남음</span></span>
                </a>
                <a href="{{ route('stores.index', $e) }}" class="group rounded-[20px] bg-white/85 px-4 py-3.5 ring-1 ring-ink/10 backdrop-blur transition active:scale-[.98]">
                    <span class="block text-[11px] font-bold tracking-wide text-mist">공연장 매장</span>
                    <span class="mt-0.5 flex items-center justify-between text-base font-bold">미리 주문 <span aria-hidden="true" class="transition group-hover:translate-x-0.5">→</span></span>
                    <span class="mt-1 block text-xs font-medium text-mist">매장 {{ $stores->count() }}곳 · 줄 없이 픽업</span>
                </a>
            </div>
        </section>

        {{-- ② 공연장 매장 --}}
        <section class="mt-10" aria-labelledby="stores-title">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <p class="eyebrow">Siren Order</p>
                    <h2 id="stores-title" class="mt-1 text-xl font-black tracking-tight">줄 서지 말고, 미리 주문</h2>
                </div>
                @if ($stores->isNotEmpty())
                    <a href="{{ route('stores.index', $e) }}" class="shrink-0 text-sm font-semibold text-stick">전체 매장</a>
                @endif
            </div>

            {{-- 이용 순서: 주문 → 번호가 노랗게 → 받기 --}}
            <ol class="mt-4 grid grid-cols-3 gap-2 text-center text-xs font-semibold">
                <li class="rounded-2xl bg-white px-2 py-3 ring-1 ring-ink/5">
                    <span class="num mx-auto grid h-7 w-7 place-items-center rounded-full bg-ink text-[11px] text-white">1</span>
                    <span class="mt-2 block">자리에서 주문</span>
                </li>
                <li class="rounded-2xl bg-white px-2 py-3 ring-1 ring-ink/5">
                    <span class="num mx-auto grid h-7 w-7 place-items-center rounded-full bg-pick text-[11px] text-ink shadow-[0_0_0_4px_rgba(255,210,63,.25)]">2</span>
                    <span class="mt-2 block">번호가 노랗게</span>
                </li>
                <li class="rounded-2xl bg-white px-2 py-3 ring-1 ring-ink/5">
                    <span class="num mx-auto grid h-7 w-7 place-items-center rounded-full bg-ink text-[11px] text-white">3</span>
                    <span class="mt-2 block">매장에서 받기</span>
                </li>
            </ol>

            @if ($stores->isEmpty())
                <p class="mt-4 rounded-3xl bg-white px-5 py-8 text-center text-sm text-mist ring-1 ring-ink/5">이 공연에는 주문 받는 매장이 아직 없어요.</p>
            @else
                <ul class="-mx-4 mt-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 pb-2 [scrollbar-width:none]">
                    @foreach ($stores as $store)
                        @php($slots = $store->capacitySlots->sortBy(fn ($s) => array_search($s->slot, array_keys($slotNames))))
                        @php($now = $slots->first(fn ($s) => $s->isOpen()))
                        @php($shots = $store->products->filter(fn ($p) => $p->image_path)->take(3)->values())
                        <li class="w-[15.5rem] shrink-0 snap-start animate-rise" style="animation-delay: {{ 80 + $loop->index * 60 }}ms">
                            <a href="{{ route('stores.menu', [$e, $store]) }}"
                               @class(['block overflow-hidden rounded-3xl bg-white ring-1 ring-ink/5 transition hover:ring-ink/20', 'opacity-60' => ! $store->is_open || ! $now])>
                                <div class="relative grid h-32 grid-cols-3 gap-0.5 bg-paper-deep">
                                    @forelse ($shots as $i => $p)
                                        <img src="{{ $p->imageUrl() }}" alt="" loading="lazy"
                                             @class(['h-full w-full object-cover', 'col-span-2 row-span-2' => $i === 0 && $shots->count() > 1, 'col-span-3' => $shots->count() === 1])>
                                    @empty
                                        <div class="fandom-soft col-span-3 flex items-end p-4">
                                            <span class="font-display text-4xl font-black text-ink/15">{{ mb_substr($store->name, 0, 2) }}</span>
                                        </div>
                                    @endforelse
                                    <span class="absolute left-3 top-3">
                                        @if (! $store->is_open)
                                            <span class="chip bg-ink/80 text-white">영업 종료</span>
                                        @elseif ($now)
                                            <span class="chip bg-white/90 text-ink"><span class="h-1.5 w-1.5 rounded-full bg-[#2BB673]" aria-hidden="true"></span>{{ $slotNames[$now->slot] }} 주문 중</span>
                                        @else
                                            <span class="chip bg-white/90 text-signal">주문 마감</span>
                                        @endif
                                    </span>
                                </div>
                                <div class="px-4 pb-4 pt-3">
                                    <h3 class="truncate text-base font-bold">{{ $store->name }}</h3>
                                    <p class="mt-0.5 truncate text-xs text-mist">{{ $store->products->take(3)->pluck('name')->join(' · ') ?: '메뉴 준비 중' }}</p>
                                    @if ($now)
                                        <div class="mt-3 flex items-center gap-2">
                                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-paper-deep" aria-hidden="true">
                                                <div class="h-full rounded-full bg-ink" style="width: {{ $now->capacity ? round($now->remaining() / $now->capacity * 100) : 0 }}%"></div>
                                            </div>
                                            <span class="num text-[11px] font-bold">{{ $now->remaining() }}<span class="font-sans font-semibold text-mist">건 남음</span></span>
                                        </div>
                                    @endif
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- ③ 지금 주문할 수 있는 메뉴 --}}
        @if ($picks->isNotEmpty())
            <section class="mt-9" aria-labelledby="picks-title">
                <h2 id="picks-title" class="text-lg font-bold">지금 주문할 수 있는 메뉴</h2>
                <ul class="mt-3 grid grid-cols-2 gap-3">
                    @foreach ($picks as $p)
                        @php($store = $stores->firstWhere('id', $p->store_id))
                        <li class="animate-rise" style="animation-delay: {{ 120 + $loop->index * 40 }}ms">
                            <a href="{{ route('stores.menu', [$e, $store]) }}" class="group block">
                                <div class="relative aspect-[4/3] overflow-hidden rounded-3xl bg-paper-deep ring-1 ring-ink/5">
                                    @if ($p->image_path)
                                        <img src="{{ $p->imageUrl() }}" alt="" loading="lazy" class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.04]">
                                    @else
                                        <div class="fandom-soft flex h-full items-end p-4">
                                            <span class="font-display text-5xl font-black leading-none text-ink/12">{{ mb_substr($p->name, 0, 1) }}</span>
                                        </div>
                                    @endif
                                    @if ($p->is_alcohol)
                                        <span class="chip absolute right-2 top-2 bg-ink/80 text-white">19+</span>
                                    @endif
                                </div>
                                <p class="product-name mt-2 truncate text-sm font-bold" data-ko="{{ $p->name }}">{{ $p->name }}</p>
                                <p class="flex items-baseline justify-between gap-2">
                                    <span class="truncate text-xs text-mist">{{ $store?->name }}</span>
                                    <span class="num shrink-0 text-sm font-bold">{{ number_format($p->price) }}<span class="font-sans text-xs font-semibold">원</span></span>
                                </p>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('stores.index', $e) }}" class="btn-stick mt-5 w-full">매장 전체 보고 주문하기</a>
            </section>
        @endif

        {{-- ④ 다른 공연 --}}
        @if ($events->count() > 1)
            <h2 class="mt-10 mb-3 text-lg font-bold">다른 공연</h2>
            <ul class="space-y-3">
                @foreach ($events->skip(1) as $e)
                    <li>
                        <a href="{{ route('concerts.show', $e) }}" class="flex items-stretch overflow-hidden rounded-2xl bg-white ring-1 ring-ink/5 transition hover:ring-stick/40">
                            <span class="fandom-gradient w-2 shrink-0" style="--theme: {{ $e->theme_color }}"></span>
                            <span class="flex flex-1 items-center justify-between gap-3 px-4 py-4">
                                <span class="min-w-0">
                                    <span class="block truncate text-base font-bold">{{ $e->artist }}</span>
                                    <span class="block truncate text-sm text-mist">{{ $e->name }}</span>
                                </span>
                                <span class="shrink-0 text-right">
                                    <span class="num block text-base font-bold">{{ $e->starts_at->format('m.d') }}</span>
                                    <span class="block text-xs text-mist">{{ $e->seatsLeft() ? number_format($e->lowestPrice()).'원~' : '매진' }}</span>
                                </span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    @else
        <div class="mt-16 text-center">
            <p class="text-lg font-bold">예정된 공연이 없어요</p>
            <p class="mt-1 text-sm text-mist">새 공연이 열리면 좌석 예매와 공연장 매장 주문이 여기에 보여요.</p>
        </div>
    @endif
@endsection
