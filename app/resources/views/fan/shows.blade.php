@extends('layouts.ticket')
@section('title', $title)
@section('genre', $genre ?? '')

@php($days = ['일', '월', '화', '수', '목', '금', '토'])

@section('content')
    <section class="tix-wrap mt-8 lg:mt-10">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="eyebrow">{{ $genre ? '장르' : '검색' }}</p>
                <h1 class="mt-1 text-[28px] font-black tracking-tight lg:text-[34px]">{{ $title }}</h1>
                <p class="mt-1 text-sm text-mist">공연 <span class="num font-bold text-ink">{{ $productions->count() }}</span>개</p>
            </div>
            @if ($productions->isNotEmpty())
                <nav class="flex rounded-full bg-paper p-1 text-sm font-semibold" aria-label="정렬">
                    @foreach (['date' => '공연일 순', 'rank' => '예매 많은 순'] as $key => $label)
                        <a href="{{ request()->fullUrlWithQuery(['sort' => $key]) }}" @class(['rounded-full px-4 py-1.5 transition', 'bg-white text-ink shadow-sm' => $sort === $key, 'text-mist hover:text-ink' => $sort !== $key])>{{ $label }}</a>
                    @endforeach
                </nav>
            @endif
        </div>

        {{-- 휴대폰 검색창 (넓은 화면은 상단 바에) --}}
        @unless ($genre)
            <form action="{{ route('shows.search') }}" method="get" role="search" class="relative mt-5 md:hidden">
                <label for="q-m" class="sr-only">공연 검색</label>
                <input id="q-m" name="q" value="{{ $q }}" placeholder="아티스트, 공연, 공연장" class="field !rounded-full pr-12" autofocus>
                <button class="absolute right-2 top-1/2 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-full bg-ink text-white" aria-label="검색">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                </button>
            </form>
        @endunless

        @if ($productions->isEmpty())
            <div class="mt-10 rounded-[28px] bg-paper px-6 py-16 text-center">
                <p class="text-lg font-bold">{{ $q !== '' || ! $genre ? '찾는 공연이 없어요' : '아직 예매할 수 있는 '.$title.' 공연이 없어요' }}</p>
                <p class="mt-1 text-sm text-mist">다른 장르를 둘러보세요.</p>
                <ul class="mt-6 flex flex-wrap justify-center gap-2">
                    @foreach (\App\Models\Production::GENRES as $key => $name)
                        @if (($genreCounts[$key] ?? 0) > 0)
                            <li><a href="{{ route('shows.genre', $key) }}" class="inline-flex items-center gap-1.5 rounded-full bg-white px-4 py-2 text-sm font-semibold ring-1 ring-ink/10 hover:ring-ink">{{ $name }} <span class="num text-xs text-mist">{{ $genreCounts[$key] }}</span></a></li>
                        @endif
                    @endforeach
                </ul>
            </div>
        @else
            <ul class="mt-8 grid grid-cols-2 gap-x-3 gap-y-9 sm:grid-cols-3 lg:grid-cols-5 lg:gap-x-5">
                @foreach ($productions as $p)
                    @php($ev = $p->nextEvent())
                    <li>
                        <a href="{{ $ev ? route('concerts.show', $ev) : '#' }}" class="group block">
                            @include('partials.poster', ['p' => $p, 'eager' => $loop->index < 5])
                            <p class="mt-3 line-clamp-2 text-[15px] font-bold leading-snug [word-break:keep-all]">{{ $p->title }}</p>
                            <p class="mt-1 truncate text-[13px] text-mist">{{ $p->venue }}</p>
                            <p class="num text-[13px] text-mist">{{ $p->period() }}</p>
                            @if ($p->opensLater())
                                @php($at = $p->salesOpenAt())
                                <p class="mt-1.5 text-[13px] font-bold text-stick">{{ $at->format('n.j') }}({{ $days[$at->dayOfWeek] }}) {{ $at->format('H:i') }} 오픈</p>
                            @elseif ($p->lowestPrice())
                                <p class="mt-1.5 text-[13px]"><span class="num font-bold">{{ number_format($p->lowestPrice()) }}</span>원~</p>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
