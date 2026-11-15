@extends('layouts.fan')
@section('title', '주문내역')
@section('tab', 'orders')

@section('content')
    <h1 class="mt-2 text-2xl font-black">주문내역</h1>

    <nav class="mt-5 grid grid-cols-3 gap-1 rounded-full bg-white p-1 text-sm font-semibold ring-1 ring-ink/5" aria-label="주문 상태">
        @foreach (['active' => '진행 중', 'done' => '받은 주문', 'cancelled' => '취소'] as $key => $label)
            <a href="{{ route('orders.index', ['tab' => $key]) }}"
               @class(['rounded-full py-2 text-center transition', 'bg-ink text-paper' => $tab === $key, 'text-mist hover:text-ink' => $tab !== $key])
               @if ($tab === $key) aria-current="page" @endif>
                {{ $label }} <span class="num text-xs">{{ $counts[$key] }}</span>
            </a>
        @endforeach
    </nav>

    <ul class="mt-5 space-y-3">
        @forelse ($orders as $o)
            <li>
                <a href="{{ route('orders.show', $o) }}" class="flex items-center gap-4 rounded-3xl bg-white p-4 ring-1 ring-ink/5 transition hover:ring-stick/40">
                    <span @class(['num grid size-16 shrink-0 place-items-center rounded-2xl text-xl font-black',
                                  'bg-pick' => $o->status->value === 4,
                                  'bg-paper text-mist line-through' => $tab === 'cancelled',
                                  'bg-paper' => $o->status->value !== 4 && $tab !== 'cancelled'])>{{ $o->pickup_code }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-bold">{{ $o->store->name }}</span>
                        <span class="block truncate text-sm text-mist">{{ $o->items->map(fn ($i) => $i->name.' '.$i->qty)->join(', ') }}</span>
                        <span class="num block text-xs text-mist">{{ ($tab === 'cancelled' ? $o->cancelled_at : $o->created_at)?->format('m.d H:i') }}</span>
                    </span>
                    <span class="shrink-0 text-right">
                        <span @class(['block text-sm font-semibold', 'text-signal' => $tab === 'cancelled'])>{{ $tab === 'cancelled' ? ($o->cancel_reason ?? '취소') : $o->status->label() }}</span>
                        <span class="num block text-xs text-mist">{{ number_format($o->total_amount) }}원{{ $o->refunded_at ? ' 환불' : '' }}</span>
                    </span>
                </a>
            </li>
        @empty
            <li class="rounded-3xl bg-white px-5 py-8 text-center">
                <p class="font-bold">{{ ['active' => '진행 중인 주문이 없어요', 'done' => '아직 받은 주문이 없어요', 'cancelled' => '취소된 주문이 없어요'][$tab] }}</p>
                @if ($tab === 'active')
                    <p class="mt-1 text-sm text-mist">내 티켓에서 매장 주문을 시작할 수 있어요.</p>
                    <a href="{{ route('tickets') }}" class="btn-ink mt-5">내 티켓 열기</a>
                @endif
            </li>
        @endforelse
    </ul>
@endsection
