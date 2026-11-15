@extends('layouts.fan')
@section('title', '내 티켓')
@section('tab', 'tickets')

@php($days = ['일', '월', '화', '수', '목', '금', '토'])

@section('content')
    <h1 class="mt-2 text-2xl font-black">내 티켓</h1>

    @if (session('just_bought'))
        <p role="status" class="mt-4 rounded-2xl bg-white/80 px-4 py-3 text-sm font-bold ring-1 ring-ink/10 animate-rise">
            {{ session('just_bought') }}장 예매했어요. 공연 날 입구에서 이 화면을 보여 주세요.
        </p>
    @endif

    @forelse ($tickets as $t)
        <article class="stub mt-5 shadow-sm animate-rise" style="--cut: 196px; animation-delay: {{ $loop->index * 60 }}ms">
            <div class="fandom-gradient h-[196px] overflow-hidden rounded-t-[18px] px-5 pt-5 text-white" style="--theme: {{ $t->event->theme_color }}">
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
                    @elseif ($t->status === 'cancelled')
                        <span class="chip bg-signal/10 text-signal">취소</span>
                    @else
                        <span class="chip bg-stick/10 text-stick">입장 전</span>
                    @endif
                </div>
                @if ($t->status === 'cancelled')
                    <p class="mt-4 rounded-2xl bg-paper px-4 py-3 text-sm">
                        <b>예매 취소됨</b> <span class="num text-xs text-mist">{{ $t->cancelled_at?->format('m.d H:i') }}</span><br>
                        <span class="text-mist">취소 수수료 <span class="num">{{ number_format($t->cancel_fee) }}</span>원 · 환불 <b class="num text-ink">{{ number_format($t->refund_amount) }}</b>원</span>
                    </p>
                @else
                    <a href="{{ route('stores.index', [$t->event, 'ticket' => $t->ticket_no]) }}" class="btn-stick mt-4 w-full">
                        이 좌석으로 매장 주문하기
                    </a>
                @endif
                @if ($t->status === 'paid')
                    @php($rate = $t->cancelFeeRate())
                    @if ($rate === null)
                        <p class="mt-3 text-center text-xs text-mist">공연 당일에는 예매를 취소할 수 없어요.</p>
                    @else
                        @php($fee = (int) (floor($t->price * $rate / 100 / 10) * 10))
                        <form method="POST" action="{{ route('tickets.cancel', $t) }}" class="mt-3 text-center"
                              onsubmit="return confirm('{{ $t->seatLabel() }} 예매를 취소할까요?\n취소 수수료 {{ number_format($fee) }}원을 빼고 {{ number_format($t->price - $fee) }}원이 환불돼요.')">
                            @csrf
                            <button class="text-xs font-semibold text-mist underline-offset-4 hover:text-signal hover:underline">
                                예매 취소 · {{ $rate ? '수수료 '.$rate.'% ('.number_format($fee).'원)' : '수수료 없음' }}
                            </button>
                        </form>
                    @endif
                @endif
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
