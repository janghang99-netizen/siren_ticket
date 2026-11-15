@extends('layouts.fan')
@section('title', '공연장 매장')
@section('tab', 'home')
@section('theme', $event->theme_color)

@php($slotNames = ['pre' => '공연 전', 'intermission' => '인터미션', 'post' => '공연 후'])

@section('content')
    <p class="eyebrow mt-2">{{ $event->venue }} · {{ $event->artist }}</p>
    <h1 class="mt-2 text-2xl font-black">공연장 매장</h1>
    <p class="mt-1 text-sm text-mist">미리 주문하고, 픽업번호가 노랗게 뜨면 매장에서 받아 가세요.</p>

    @if ($ticket)
        <div class="mt-5 flex items-center gap-3 rounded-2xl bg-ink px-4 py-3 text-paper">
            <span class="num rounded-lg bg-white px-2 py-1 text-sm font-black text-ink">{{ $ticket->section->code }}</span>
            <p class="text-sm"><b>{{ $ticket->seatLabel() }}</b> 티켓으로 주문해요</p>
        </div>
    @else
        <a href="{{ route('tickets') }}" class="mt-5 block rounded-2xl bg-white px-4 py-3 text-sm ring-1 ring-ink/5">
            티켓을 연결하면 매장에서 내 좌석을 알 수 있어요. <span class="font-semibold text-stick">내 티켓 열기</span>
        </a>
    @endif

    <ul class="mt-6 space-y-3">
        @forelse ($stores as $store)
            @php($slots = $store->capacitySlots->sortBy(fn ($s) => array_search($s->slot, array_keys($slotNames))))
            @php($now = $slots->first(fn ($s) => $s->isOpen()))
            <li class="animate-rise" style="animation-delay: {{ $loop->index * 50 }}ms">
                <a href="{{ route('stores.menu', [$event, $store]) }}"
                   @class(['block rounded-3xl bg-white p-5 ring-1 ring-ink/5 transition hover:ring-stick/40', 'opacity-60' => ! $store->is_open || ! $now])>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="text-lg font-bold">{{ $store->name }}</h2>
                            <p class="mt-0.5 truncate text-sm text-mist">{{ $store->products->take(3)->pluck('name')->join(' · ') }}</p>
                        </div>
                        @if (! $store->is_open)
                            <span class="chip shrink-0 bg-ink/10">영업 종료</span>
                        @elseif ($now)
                            <span class="chip shrink-0 bg-stick/10 text-stick">{{ $slotNames[$now->slot] }} 주문 중</span>
                        @else
                            <span class="chip shrink-0 bg-signal/10 text-signal">주문 마감</span>
                        @endif
                    </div>

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
            <li class="rounded-3xl bg-white px-5 py-8 text-center text-sm text-mist">이 공연에는 주문 받는 매장이 아직 없어요.</li>
        @endforelse
    </ul>
@endsection
