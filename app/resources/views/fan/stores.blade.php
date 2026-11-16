@extends('layouts.fan')
@section('title', $event ? '공연장 매장' : '매장 주문')
@section('tab', 'order')
@if ($event) @section('theme', $event->theme_color) @endif

@php($slotNames = ['pre' => '공연 전', 'intermission' => '인터미션', 'post' => '공연 후'])
@php($menuUrl = fn ($s) => $event ? route('stores.menu', [$event, $s]) : route('shop.menu', $s))

@section('content')
    @if ($event)
        <p class="eyebrow mt-2">{{ $event->venue }} · {{ $event->artist }}</p>
        <h1 class="mt-2 text-2xl font-black">공연장 매장</h1>
    @else
        <p class="eyebrow mt-2">바로 픽업 가능</p>
        <h1 class="mt-2 text-2xl font-black">전체 매장</h1>
    @endif
    <p class="mt-1 text-sm text-mist">미리 주문하고, 픽업번호가 노랗게 뜨면 매장에서 받아 가세요.</p>

    @if ($ticket)
        <div class="mt-5 flex items-center gap-3 rounded-2xl bg-ink px-4 py-3 text-paper">
            <span class="num rounded-lg bg-white px-2 py-1 text-sm font-black text-ink">{{ $ticket->section->code }}</span>
            <p class="text-sm"><b>{{ $ticket->seatLabel() }}</b> 티켓으로 주문해요</p>
        </div>
    @elseif ($event && $ticketing)
        <a href="{{ route('tickets') }}" class="mt-5 block rounded-2xl bg-white px-4 py-3 text-sm ring-1 ring-ink/5">
            티켓을 연결하면 매장에서 내 좌석을 알 수 있어요. <span class="font-semibold text-stick">내 티켓 열기</span>
        </a>
    @endif

    {{-- 오늘 공연이 있으면: 공연 시간대(공연 전·인터미션·공연 후)에 맞춰 받는 미리 주문 --}}
    @foreach ($events as $e)
        <a href="{{ route('stores.index', $e) }}" class="mt-4 flex items-center justify-between gap-3 rounded-2xl bg-ink px-4 py-3 text-sm text-paper">
            <span class="min-w-0"><span class="block text-[11px] font-bold text-paper/60">오늘 공연 · {{ $e->starts_at->format('H:i') }}</span>
                <b class="block truncate">{{ $e->artist }} {{ $e->name }}</b></span>
            <span class="shrink-0 font-semibold">공연 시간 맞춰 주문 →</span>
        </a>
    @endforeach

    <ul class="mt-6 space-y-3">
        @forelse ($stores as $store)
            @php($slots = $event ? $store->capacitySlots->sortBy(fn ($s) => array_search($s->slot, array_keys($slotNames))) : collect())
            @php($now = $slots->first(fn ($s) => $s->isOpen()))
            @php($closed = ! $store->is_open || ($event && ! $now))
            <li class="animate-rise" style="animation-delay: {{ $loop->index * 50 }}ms">
                <a href="{{ $menuUrl($store) }}"
                   @class(['block rounded-3xl bg-white p-5 ring-1 ring-ink/5 transition hover:ring-stick/40', 'opacity-60' => $closed])>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            @if ($store->area)<p class="text-xs font-semibold text-mist">{{ $store->area }}</p>@endif
                            <h2 class="text-lg font-bold">{{ $store->name }}</h2>
                            <p class="mt-0.5 truncate text-sm text-mist">{{ $store->products->take(3)->pluck('name')->join(' · ') ?: '메뉴 준비 중' }}</p>
                        </div>
                        @if (! $store->is_open)
                            <span class="chip shrink-0 bg-ink/10">준비중</span>
                        @elseif (! $event)
                            <span class="chip shrink-0 bg-stick/10 text-stick">주문 가능</span>
                        @elseif ($now)
                            <span class="chip shrink-0 bg-stick/10 text-stick">{{ $slotNames[$now->slot] }} 주문 중</span>
                        @else
                            <span class="chip shrink-0 bg-signal/10 text-signal">주문 마감</span>
                        @endif
                    </div>
                    @if (! $event && ($store->hours || $store->address))
                        <p class="mt-2 truncate text-xs text-mist">{{ collect([$store->hours, $store->address])->filter()->join(' · ') }}</p>
                    @endif

                    @if ($now)
                        <div class="mt-4">
                            <div class="flex justify-between text-xs">
                                <span class="text-mist">{{ $slotNames[$now->slot] }} 남은 주문</span>
                                <span class="num font-bold">{{ $now->remaining() }}<span class="text-mist">/{{ $now->capacity }}</span></span>
                            </div>
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-paper-deep" aria-hidden="true">
                                <div class="h-full rounded-full bg-stick" style="width: {{ $now->capacity ? round($now->remaining() / $now->capacity * 100) : 0 }}%"></div>
                            </div>
                        </div>
                    @endif
                </a>
            </li>
        @empty
            <li class="rounded-3xl bg-white px-5 py-8 text-center text-sm text-mist">{{ $event ? '이 공연에는 주문 받는 매장이 아직 없어요.' : '지금 주문 받는 매장이 아직 없어요.' }}</li>
        @endforelse
    </ul>
@endsection
