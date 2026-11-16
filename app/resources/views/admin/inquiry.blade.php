@extends('layouts.backoffice')
@section('title', $inquiry->title)
@section('eyebrow', '1:1 문의 #'.$inquiry->id)

@section('content')
<div class="grid max-w-4xl gap-5">
    <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
        <p class="text-xs text-mist">{{ $inquiry->categoryLabel() }}{{ $inquiry->store ? ' · '.$inquiry->store->name : '' }} · {{ $inquiry->created_at->format('Y.m.d H:i') }} · 고객 <span class="num">{{ $inquiry->customer?->maskedPhone() }}</span>
            @if ($inquiry->customer) <a href="{{ route('admin.customers.show', $inquiry->customer) }}" class="font-semibold text-stick">고객 보기</a>@endif</p>
        @if ($order)
            <p class="mt-1 text-xs">관련 주문 <a href="{{ route('admin.orders.show', $order) }}" class="num font-semibold text-stick">{{ $order->order_no }}</a> · {{ number_format($order->total_amount) }}원</p>
        @endif
        <div class="mt-4 whitespace-pre-line text-sm">{{ $inquiry->body }}</div>
    </section>

    <form method="POST" action="{{ route('admin.inquiries.answer', $inquiry) }}" class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
        @csrf @method('PUT')
        <label for="answer" class="label">답변 @if ($inquiry->isAnswered())<span class="font-normal text-mist">{{ $inquiry->answered_at->format('m.d H:i') }} · {{ $inquiry->answeredBy?->name }}</span>@endif</label>
        <textarea id="answer" name="answer" rows="6" class="field" required>{{ old('answer', $inquiry->answer) }}</textarea>
        @error('answer')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        <div class="mt-3 flex items-center gap-3">
            <button class="btn-ink !py-2.5 text-sm">{{ $inquiry->isAnswered() ? '답변 고치기' : '답변하고 문자 알림' }}</button>
            <a href="{{ route('admin.inquiries.index') }}" class="btn-ghost !py-2.5 text-sm">목록으로</a>
        </div>
    </form>
</div>
@endsection
