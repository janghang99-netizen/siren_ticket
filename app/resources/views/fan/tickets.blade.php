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
                {{-- NFT 티켓 + 30초마다 바뀌는 입장 코드 (캡처로는 입장 불가) --}}
                @if ($t->token)
                    <div class="mt-4 rounded-2xl bg-paper px-4 py-3 text-xs">
                        <div class="flex items-center justify-between">
                            <span class="font-bold">NFT 티켓 <span class="num text-mist">#{{ $t->token->token_id }}</span></span>
                            <span class="chip bg-ink/5">{{ $t->token->statusLabel() }} · 양도 불가</span>
                        </div>
                        <p class="num mt-1 text-mist">내 지갑 {{ \App\Models\TicketToken::short($t->token->owner_address) }} · {{ $t->token->network }} ·
                            @if ($t->token->mint_tx && $t->token->explorerUrl($t->token->mint_tx))
                                <a href="{{ $t->token->explorerUrl($t->token->mint_tx) }}" target="_blank" rel="noopener" class="row-link text-ink">발행 기록 보기</a>
                            @elseif ($t->token->status === 'pending')
                                <span>체인 기록 대기 중</span>
                            @else
                                발행 {{ \App\Models\TicketToken::short((string) $t->token->mint_tx) }}
                            @endif
                        </p>
                    </div>
                    @if ($t->status === 'paid' && auth('member')->id() === $t->member_id)
                        <div class="mt-3 rounded-2xl bg-ink p-4 text-center text-paper"
                             x-data="{ code: '', left: 25, show: false,
                                 async load() { try { const r = await api('{{ route('tickets.entry-code', $t) }}'); this.code = r.code; this.left = r.refresh } catch (e) { this.code = '' } },
                                 open() { this.show = true; this.load(); setInterval(() => { if (--this.left <= 0) this.load() }, 1000) } }">
                            <button type="button" x-show="!show" @click="open()" class="w-full text-sm font-bold">입장 코드 보기 (공연장 입구에서)</button>
                            <div x-show="show" x-cloak>
                                <p class="text-[11px] text-paper/60">입구 직원에게 보여 주세요 · <span class="num" x-text="left"></span>초 뒤 바뀌어요</p>
                                <p class="num mt-2 break-all text-lg font-black tracking-wide" x-text="code || '불러오는 중…'"></p>
                                <p class="mt-1 text-[11px] text-paper/60">캡처한 화면은 쓸 수 없어요</p>
                            </div>
                        </div>
                    @endif
                @endif

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
                              onsubmit="return confirm('{{ $t->seatLabel() }} 예매를 취소할까요?\n취소 수수료 {{ number_format($fee) }}원을 빼고 {{ number_format(max(0, $t->paid_amount - $fee)) }}원이 환불돼요.{{ $t->mileage_used ? '\n쓴 마일리지는 돌려드려요.' : '' }}')">
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
            <p class="text-lg font-bold">아직 티켓이 없어요</p>
            <p class="mt-1 text-sm text-mist">{{ auth('member')->check() ? '예매한 티켓은 여기에 모두 보여요.' : '로그인하면 어느 기기에서든 내 티켓을 볼 수 있어요.' }}</p>
            <a href="{{ route('home') }}" class="btn-ink mt-5">공연 보러 가기</a>
        </div>
    @endforelse

    @guest('member')
        <div class="mt-10 flex gap-2">
            <a href="{{ route('member.login', ['return' => route('tickets', absolute: false)]) }}" class="btn-ink flex-1">로그인하고 내 티켓 보기</a>
            <a href="{{ route('member.signup') }}" class="btn-ghost">회원가입</a>
        </div>
    @endguest
@endsection
