@extends('layouts.backoffice')
@section('title', '주문 '.$order->pickup_code)
@section('eyebrow', $order->store->name)

@section('actions')
    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('partner.sales') }}" class="btn-ghost !py-2.5 text-sm">목록으로</a>
@endsection

{{-- D2 구매자 취소 요청 응답: 거절은 사유 필수 (정당한 사유 없이 거부 불가) --}}
@if ($order->hasPendingCancelRequest())
    @push('order-aside')
        <section class="rounded-3xl bg-white p-6 ring-1 ring-signal/30" x-data="{ reject: false }">
            <h2 class="font-bold text-signal">구매자가 취소를 요청했어요</h2>
            <p class="mt-1 text-sm">“{{ $order->cancel_request_reason }}”</p>
            <p class="mt-1 text-xs text-mist">상품 하자 등 정당한 사유가 있으면 거절할 수 없어요. 거절할 때는 사유를 적어야 하고 구매자에게 그대로 보여요.</p>
            <div class="mt-3 flex gap-2">
                <form method="POST" action="{{ route('partner.orders.cancel-request', $order) }}" onsubmit="return confirm('취소를 승인하고 {{ number_format($order->total_amount) }}원을 환불할까요?')">
                    @csrf @method('PATCH')
                    <input type="hidden" name="decision" value="approve">
                    <button class="btn-ink !py-2 text-sm">승인 · 환불</button>
                </form>
                <button type="button" @click="reject = !reject" class="btn-ghost !py-2 text-sm">거절</button>
            </div>
            <form x-show="reject" x-cloak method="POST" action="{{ route('partner.orders.cancel-request', $order) }}" class="mt-3">
                @csrf @method('PATCH')
                <input type="hidden" name="decision" value="reject">
                <input name="response" class="field !py-2.5" placeholder="거절 사유 (예: 이미 조리가 끝났어요)" required aria-label="거절 사유">
                <button class="btn mt-2 w-full bg-signal !py-2 text-sm text-white">거절하기</button>
            </form>
            @error('rule')<p class="mt-2 text-sm text-signal">{{ $message }}</p>@enderror
        </section>
    @endpush
@endif

@section('content')
    @include('backoffice.order-detail')
@endsection
