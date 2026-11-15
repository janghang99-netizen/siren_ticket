@extends('layouts.backoffice')
@section('title', '주문 '.$order->pickup_code)
@section('eyebrow', $order->store->name)

@section('actions')
    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('partner.sales') }}" class="btn-ghost !py-2.5 text-sm">목록으로</a>
@endsection

@section('content')
    @include('backoffice.order-detail')
@endsection
