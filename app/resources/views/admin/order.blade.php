@extends('layouts.backoffice')
@section('title', '주문 '.$order->pickup_code)
@section('eyebrow', ($order->store->vendor?->name ?? '').' · '.$order->store->name)

@section('actions')
    <a href="{{ route('admin.stores.show', $order->store_id) }}" class="btn-ghost !py-2.5 text-sm">매장 보기</a>
    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('admin.orders.index') }}" class="btn-ghost !py-2.5 text-sm">목록으로</a>
@endsection

@if (in_array($order->status->value, [5, 6], true))
    @push('order-aside')
        <form method="POST" action="{{ route('admin.orders.refund', $order) }}" class="rounded-3xl bg-white p-6 ring-1 ring-signal/30"
              onsubmit="return confirm('이 주문을 환불할까요? 되돌릴 수 없어요.')">
            @csrf
            <h2 class="font-bold text-signal">운영사 환불</h2>
            <p class="mt-1 text-sm text-mist">
                {{ $order->status->value === 6 ? '이미 정산된 주문이에요. 환불하면 업체의 다음 정산에서 이 주문의 정산금을 공제해요.' : '수령완료된 주문이에요. 환불하면 정산 대상에서 빠져요.' }}
            </p>
            <label for="reason" class="label mt-3">환불 사유</label>
            <input id="reason" name="reason" class="field !py-2.5" placeholder="예: 상품 하자 확인" required>
            @error('reason')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            <button class="btn mt-3 w-full bg-signal !py-2.5 text-sm text-white">{{ number_format($order->total_amount) }}원 환불</button>
        </form>
    @endpush
@endif

@section('content')
    @include('backoffice.order-detail')
@endsection
