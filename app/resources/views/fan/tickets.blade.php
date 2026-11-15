@extends('layouts.fan')
@section('title', '내 티켓')
@section('tab', 'tickets')

@php($days = ['일', '월', '화', '수', '목', '금', '토'])

@section('content')
    <h1 class="mt-2 text-2xl font-black">내 티켓</h1>

    @if (session('just_bought'))
        <p role="status" class="mt-4 rounded-2xl bg-pick px-4 py-3 text-sm font-bold animate-rise">
            {{ session('just_bought') }}장 예매했어요. 공연 날 입구에서 이 화면을 보여 주세요.
        </p>
    @endif

    @forelse ($tickets as $t)
        <article class="stub mt-5 shadow-sm animate-rise" style="--cut: 196px; animation-delay: {{ $loop->index * 60 }}ms">
            <div class="h-[196px] overflow-hidden rounded-t-[18px] px-5 pt-5 text-paper" style="background: linear-gradient(160deg, {{ $t->event->theme_color }}, #17132B 85%)">
                <p class="eyebrow !text-paper/60">{{ $t->event->venue }} · {{ $t->section->tier }}석</p>
                <h2 class="mt-2 truncate text-2xl font-black">{{ $t->event->artist }}</h2>
                <p class="num mt-1 text-sm text-paper/80">{{ $t->event->starts_at->format('Y.m.d') }} {{ $days[$t->event->starts_at->dayOfWeek] }} {{ $t->event->starts_at->format('H:i') }}</p>
                <div class="mt-5 flex items-end gap-4">
                    @foreach ([['구역', $t->section->code], ['열', $t->row_no], ['번', $t->seat_no]] as [$label, $value])
                        <div>
                            <p class="text-[11px] font-semibold text-paper/60">{{ $label }}</p>
                            <p class="num text-[34px] font-black leading-none">{{ $value }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="px-5 pb-5 pt-6">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs text-mist">티켓 번호</p>
                        <p class="num truncate text-base font-bold tracking-wider">{{ $t->ticket_no }}</p>
                    </div>
                    @if ($t->status === 'used')
                        <span class="chip bg-ink/10 text-ink">입장 완료</span>
                    @else
                        <span class="chip bg-stick/10 text-stick">입장 전</span>
                    @endif
                </div>
                <a href="{{ route('stores.index', [$t->event, 'ticket' => $t->ticket_no]) }}" class="btn-stick mt-4 w-full">
                    이 좌석으로 매장 주문하기
                </a>
            </div>
        </article>
    @empty
        <div class="mt-8 rounded-3xl bg-white px-5 py-8 text-center ring-1 ring-ink/5">
            <p class="text-lg font-bold">{{ $searched ? '이 번호로 예매한 티켓이 없어요' : '아직 티켓이 없어요' }}</p>
            <p class="mt-1 text-sm text-mist">{{ $searched ? '예매할 때 적은 번호인지 확인해 주세요.' : '예매한 적이 있다면 휴대폰 번호로 찾아보세요.' }}</p>
            <a href="{{ route('home') }}" class="btn-ink mt-5">공연 보러 가기</a>
        </div>
    @endforelse

    <form method="GET" action="{{ route('tickets') }}" class="mt-10">
        <label for="phone" class="label">다른 기기에서 예매했나요?</label>
        <div class="flex gap-2">
            <input id="phone" name="phone" class="field" inputmode="tel" placeholder="예매한 휴대폰 번호" autocomplete="tel">
            <button class="btn-ghost shrink-0">찾기</button>
        </div>
    </form>
@endsection
