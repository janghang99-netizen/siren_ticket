@extends('layouts.backoffice')
@section('title', $coupon->exists ? $coupon->name : '운영사 쿠폰 만들기')
@section('eyebrow', '쿠폰 · 운영사 부담')

@section('content')
    <form method="POST" action="{{ $coupon->exists ? route('admin.coupons.update', $coupon) : route('admin.coupons.store') }}" class="max-w-3xl rounded-3xl bg-white p-6 ring-1 ring-ink/5">
        @csrf
        @if ($coupon->exists) @method('PUT') @endif
        @include('backoffice.coupon-fields', ['platform' => true])
        <div class="mt-6 flex gap-2">
            <button class="btn-ink">저장</button>
            <a href="{{ route('admin.coupons.index') }}" class="btn-ghost">취소</a>
        </div>
    </form>
@endsection
