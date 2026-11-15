@extends('layouts.fan')
@section('title', '결제')
@section('tab', 'orders')

@section('content')
    <p class="eyebrow mt-2">{{ $order->store->name }}</p>
    <h1 class="mt-2 text-2xl font-black">결제하기</h1>

    <div class="mt-6 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
        <ul class="space-y-2 text-sm">
            @foreach ($order->items as $item)
                <li class="flex justify-between gap-3">
                    <span><span class="product-name" data-ko="{{ $item->name }}">{{ $item->name }}</span>@if ($item->option_name) · {{ $item->option_name }}@endif <span class="text-mist">× {{ $item->qty }} · {{ $item->dine_option }}</span></span>
                    <span class="num shrink-0">{{ number_format($item->line_total) }}</span>
                </li>
            @endforeach
        </ul>
        <div class="mt-4 flex items-baseline justify-between border-t border-ink/5 pt-4">
            <span class="font-bold">결제 금액</span>
            <span class="num text-2xl font-black">{{ number_format($order->total_amount) }}<span class="font-sans text-base">원</span></span>
        </div>
    </div>

    <p class="mt-4 rounded-2xl bg-white/70 px-4 py-3 text-sm ring-1 ring-ink/10">
        개발용 결제 화면이에요. 실제 서비스에서는 이 자리에 결제사 결제창이 열립니다.
        {{ config('siren.pending_ttl_minutes') }}분 안에 결제하지 않으면 주문이 취소돼요.
    </p>

    <form method="POST" action="{{ route('orders.confirm', $order) }}" class="mt-6">
        @csrf
        <button class="btn-ink w-full text-base">카드로 {{ number_format($order->total_amount) }}원 결제</button>
    </form>
@endsection
