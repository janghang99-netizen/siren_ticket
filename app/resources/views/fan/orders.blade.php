@extends('layouts.fan')
@section('title', '주문내역')
@section('tab', 'orders')

@section('content')
    <h1 class="mt-2 text-2xl font-black">주문내역</h1>

    <ul class="mt-6 space-y-3">
        @forelse ($orders as $o)
            <li>
                <a href="{{ route('orders.show', $o) }}" class="flex items-center gap-4 rounded-3xl bg-white p-4 ring-1 ring-ink/5 transition hover:ring-stick/40">
                    <span @class(['num grid size-16 shrink-0 place-items-center rounded-2xl text-xl font-black',
                                  'bg-pick' => $o->status->value === 4,
                                  'bg-paper' => $o->status->value !== 4])>{{ $o->pickup_code }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-bold">{{ $o->store->name }}</span>
                        <span class="block truncate text-sm text-mist">{{ $o->items->map(fn ($i) => $i->name.' '.$i->qty)->join(', ') }}</span>
                    </span>
                    <span class="shrink-0 text-right">
                        <span class="block text-sm font-semibold">{{ $o->status->label() }}</span>
                        <span class="num block text-xs text-mist">{{ number_format($o->total_amount) }}</span>
                    </span>
                </a>
            </li>
        @empty
            <li class="rounded-3xl bg-white px-5 py-8 text-center">
                <p class="font-bold">아직 주문한 게 없어요</p>
                <p class="mt-1 text-sm text-mist">내 티켓에서 매장 주문을 시작할 수 있어요.</p>
                <a href="{{ route('tickets') }}" class="btn-ink mt-5">내 티켓 열기</a>
            </li>
        @endforelse
    </ul>
@endsection
