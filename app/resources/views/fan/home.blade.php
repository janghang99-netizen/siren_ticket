@extends('layouts.fan')
@section('title', '공연')
@section('tab', 'home')

@php($days = ['일', '월', '화', '수', '목', '금', '토'])

@section('content')
    @if ($headliner)
        @php($e = $headliner)
        <section class="relative mt-2 overflow-hidden rounded-[28px] bg-ink text-paper animate-rise">
            <div class="absolute inset-0 opacity-60" style="background: radial-gradient(120% 70% at 50% 0%, {{ $e->theme_color }} 0%, transparent 60%)"></div>
            <div class="relative px-5 pt-5">
                <div class="flex items-center justify-between">
                    <span class="eyebrow !text-paper/60">{{ $e->venue }}</span>
                    @if ($e->salesOpen())
                        <span class="chip bg-pick text-ink">예매 중</span>
                    @else
                        <span class="chip bg-paper/15 text-paper">예매 오픈 {{ $e->sales_open_at?->format('n.j H:i') }}</span>
                    @endif
                </div>
                <h1 class="mt-4 text-[44px] font-black leading-[1.02] tracking-tight">{{ $e->artist }}</h1>
                <p class="mt-2 text-base font-medium text-paper/75">{{ $e->name }}</p>
                <p class="num mt-5 text-2xl font-bold">
                    {{ $e->starts_at->format('m.d') }} <span class="text-paper/50">{{ $days[$e->starts_at->dayOfWeek] }}</span> {{ $e->starts_at->format('H:i') }}
                </p>
            </div>

            <div class="relative px-6 pt-2">
                @include('partials.bowl', ['event' => $e, 'class' => 'w-full'])
            </div>

            <div class="relative flex items-center justify-between gap-3 px-5 pb-5 pt-2">
                <div>
                    <p class="text-xs text-paper/60">남은 좌석</p>
                    <p class="num text-xl font-bold">{{ number_format($e->seatsLeft()) }}<span class="ml-1 font-sans text-sm font-semibold text-paper/60">석</span></p>
                </div>
                <a href="{{ route('concerts.show', $e) }}" class="btn bg-paper text-ink hover:bg-white">좌석 고르기</a>
            </div>
        </section>

        @if ($events->count() > 1)
            <h2 class="mt-10 mb-3 text-lg font-bold">다른 공연</h2>
            <ul class="space-y-3">
                @foreach ($events->skip(1) as $e)
                    <li>
                        <a href="{{ route('concerts.show', $e) }}" class="flex items-stretch overflow-hidden rounded-2xl bg-white ring-1 ring-ink/5 transition hover:ring-stick/40">
                            <span class="w-2 shrink-0" style="background: {{ $e->theme_color }}"></span>
                            <span class="flex flex-1 items-center justify-between gap-3 px-4 py-4">
                                <span class="min-w-0">
                                    <span class="block truncate text-base font-bold">{{ $e->artist }}</span>
                                    <span class="block truncate text-sm text-mist">{{ $e->name }}</span>
                                </span>
                                <span class="text-right">
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
            <p class="mt-1 text-sm text-mist">새 공연이 열리면 여기에 보여요.</p>
        </div>
    @endif
@endsection
