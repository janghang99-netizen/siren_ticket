@extends('layouts.backoffice')
@section('title', '티켓 '.$ticket->ticket_no)
@section('eyebrow', $ticket->event->artist.' · '.$ticket->event->starts_at->format('Y.m.d H:i'))

@section('actions')
    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('admin.tickets.index') }}" class="btn-ghost !py-2.5 text-sm">목록으로</a>
@endsection

@section('content')
<div class="grid gap-5 xl:grid-cols-[1fr_340px]">
    <div class="space-y-5">
        <section class="overflow-hidden rounded-3xl bg-white ring-1 ring-ink/5">
            <div class="px-6 py-5 text-paper" style="background: linear-gradient(150deg, {{ $ticket->event->theme_color }}, #17132B 80%)">
                <p class="eyebrow !text-paper/60">{{ $ticket->event->venue }} · {{ $ticket->section->tier }}석</p>
                <p class="mt-1 text-xl font-black">{{ $ticket->event->name }}</p>
                <div class="mt-4 flex gap-6">
                    @foreach ([['구역', $ticket->section->code], ['열', $ticket->row_no], ['번', $ticket->seat_no]] as [$l, $v])
                        <div><p class="text-xs text-paper/60">{{ $l }}</p><p class="num text-3xl font-black">{{ $v }}</p></div>
                    @endforeach
                </div>
            </div>
            <dl class="grid gap-x-8 gap-y-2 p-6 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-3"><dt class="text-mist">상태</dt><dd class="font-semibold">{{ $ticket->statusLabel() }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-mist">가격</dt><dd class="num font-bold">{{ number_format($ticket->price) }}원</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-mist">예매자</dt><dd>{{ $ticket->holder_name }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-mist">휴대폰</dt><dd class="num">{{ preg_replace('/(\d{3})(\d{3,4})(\d{4})/', '$1-****-$3', $ticket->holder_phone) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-mist">예매</dt><dd class="num">{{ $ticket->paid_at?->format('Y.m.d H:i:s') }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-mist">약관 동의</dt><dd class="num">{{ $ticket->terms_agreed_at?->format('Y.m.d H:i:s') ?? '—' }}</dd></div>
                @if ($ticket->checked_in_at)<div class="flex justify-between gap-3"><dt class="text-mist">입장</dt><dd class="num">{{ $ticket->checked_in_at->format('m.d H:i') }}</dd></div>@endif
                @if ($ticket->status === 'cancelled')
                    <div class="flex justify-between gap-3"><dt class="text-mist">취소</dt><dd class="num">{{ $ticket->cancelled_at?->format('m.d H:i') }} · {{ $ticket->cancelled_by === 'admin' ? '운영사' : '예매자' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-mist">취소 수수료 · 환불</dt><dd class="num">{{ number_format($ticket->cancel_fee) }} · <b>{{ number_format($ticket->refund_amount) }}</b>원</dd></div>
                @endif
            </dl>
        </section>

        <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <h2 class="font-bold">이 티켓으로 한 매장 주문 <span class="num text-mist">{{ $orders->count() }}</span></h2>
            @forelse ($orders as $o)
                <a href="{{ route('admin.orders.show', $o) }}" class="mt-3 flex items-center justify-between gap-3 rounded-2xl bg-paper px-4 py-3 text-sm transition hover:ring-1 hover:ring-stick/40">
                    <span><b class="num">{{ $o->pickup_code }}</b> {{ $o->store->name }} <span class="text-mist">{{ $o->items->map(fn ($i) => $i->name.' ×'.$i->qty)->join(', ') }}</span></span>
                    <span class="shrink-0"><span class="num font-bold">{{ number_format($o->total_amount) }}</span> <span class="chip bg-white">{{ $o->status->label() }}</span></span>
                </a>
            @empty
                <p class="mt-2 text-sm text-mist">아직 없어요.</p>
            @endforelse
        </section>
    </div>

    <aside class="space-y-5">
        @if ($others->isNotEmpty())
            <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
                <h2 class="font-bold">같은 예매자의 다른 티켓</h2>
                <ul class="mt-3 space-y-1 text-sm">
                    @foreach ($others as $o)
                        <li><a href="{{ route('admin.tickets.show', $o) }}" class="row-link">{{ $o->seatLabel() }}</a> <span class="text-mist">{{ $o->statusLabel() }}</span></li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($ticket->status === 'paid')
            <form method="POST" action="{{ route('admin.tickets.cancel', $ticket) }}" class="rounded-3xl bg-white p-6 ring-1 ring-signal/30"
                  onsubmit="return confirm('운영사 취소는 전액 환불이에요. 취소할까요?')">
                @csrf @method('PATCH')
                <h2 class="font-bold text-signal">운영사 취소</h2>
                <p class="mt-1 text-sm text-mist">수수료 없이 전액 환불하고 좌석을 다시 판매해요.
                    @if ($feeRate !== null) 예매자가 지금 직접 취소하면 수수료 {{ $feeRate }}%가 붙어요.@else 공연 당일이라 예매자는 직접 취소할 수 없어요.@endif
                </p>
                <button class="btn mt-3 w-full bg-signal !py-2.5 text-sm text-white">전액 환불하고 취소</button>
            </form>
        @endif
    </aside>
</div>
@endsection
