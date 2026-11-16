@extends('layouts.backoffice')
@section('title', '공연 일정')
@section('eyebrow', '좌석티켓')

@php($days = ['일', '월', '화', '수', '목', '금', '토'])

@section('actions')
    <a href="{{ route('admin.events.create') }}" class="btn-ink !py-2.5 text-sm">공연 등록</a>
@endsection

@section('content')
    @if ($events->isEmpty())
        <div class="rounded-3xl bg-white px-6 py-12 text-center ring-1 ring-ink/5">
            <p class="text-lg font-bold">등록된 공연이 없어요</p>
            <p class="mt-1 text-sm text-mist">공연을 등록하면 좌석 구역과 매장별 주문 시간대가 함께 만들어져요.</p>
            <a href="{{ route('admin.events.create') }}" class="btn-ink mt-5">첫 공연 등록</a>
        </div>
    @else
        <ul class="grid gap-4 xl:grid-cols-2">
            @foreach ($events as $e)
                @php($capacity = $e->sections->sum('capacity'))
                @php($sold = $e->sections->sum('sold'))
                @php($past = $e->starts_at->isPast())
                <li data-href="{{ route('admin.events.edit', $e->id) }}" @class(['flex cursor-pointer gap-4 rounded-3xl bg-white p-4 ring-1 ring-ink/5 transition hover:ring-stick/40', 'opacity-60' => $past])>
                    <div class="w-28 shrink-0 rounded-2xl bg-ink p-1.5">
                        @include($e->seat_map_id ? 'partials.seatmap' : 'partials.bowl', ['event' => $e, 'class' => 'w-full'])
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="num text-sm font-bold">{{ $e->starts_at->format('Y.m.d') }} {{ $days[$e->starts_at->dayOfWeek] }} {{ $e->starts_at->format('H:i') }}</p>
                                <h2 class="truncate text-lg font-black">{{ $e->artist }}</h2>
                                <p class="truncate text-sm text-mist">{{ $e->name }}</p>
                            </div>
                            @if ($e->isCancelled())
                                <span class="chip shrink-0 bg-signal/10 text-signal">공연 취소</span>
                            @elseif ($past)
                                <span class="chip shrink-0 bg-ink/10">종료</span>
                            @elseif ($e->salesOpen())
                                <span class="chip shrink-0 bg-pick">예매 중</span>
                            @else
                                <span class="chip shrink-0 bg-stick/10 text-stick">오픈 {{ $e->sales_open_at?->format('m.d H:i') }}</span>
                            @endif
                        </div>
                        <div class="mt-3">
                            <div class="flex justify-between text-xs">
                                <span class="text-mist">판매 <b class="num text-ink">{{ number_format($sold) }}</b> / {{ number_format($capacity) }}석</span>
                                <span class="num font-bold">{{ number_format((int) $e->revenue) }}원</span>
                            </div>
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-paper-deep" aria-hidden="true">
                                <div class="h-full rounded-full" style="width: {{ $capacity ? round($sold / $capacity * 100) : 0 }}%; background: {{ $e->theme_color }}"></div>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <a href="{{ route('admin.events.edit', $e->id) }}" class="btn-ghost !px-3 !py-2 text-xs">수정</a>
                            <a href="{{ route('admin.tickets.index', ['event' => $e->id]) }}" class="btn-ghost !px-3 !py-2 text-xs">티켓 내역</a>
                            <a href="{{ route('concerts.show', $e) }}" target="_blank" class="btn-ghost !px-3 !py-2 text-xs">예매 페이지</a>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
