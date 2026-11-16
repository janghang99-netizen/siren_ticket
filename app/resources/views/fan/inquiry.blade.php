@extends('layouts.fan')
@section('title', '1:1 문의')

@section('header-action')
    <a href="{{ route('inquiries.index') }}" class="text-sm font-semibold text-stick">내 문의</a>
@endsection

@section('content')
    <p class="eyebrow mt-2">{{ $inquiry->categoryLabel() }}{{ $inquiry->store ? ' · '.$inquiry->store->name : '' }}</p>
    <h1 class="mt-2 text-xl font-black">{{ $inquiry->title }}</h1>
    <p class="mt-1 text-xs text-mist">{{ $inquiry->created_at->format('Y.m.d H:i') }}@if ($inquiry->order_no) · 주문 <span class="num">{{ $inquiry->order_no }}</span>@endif</p>
    <div class="mt-4 whitespace-pre-line rounded-3xl bg-white p-5 text-sm ring-1 ring-ink/5">{{ $inquiry->body }}</div>

    @if ($inquiry->isAnswered())
        <section class="mt-4 rounded-3xl bg-ink p-5 text-sm text-paper" aria-label="답변">
            <p class="text-xs font-bold text-paper/60">답변 · {{ $inquiry->answered_at->format('Y.m.d H:i') }}</p>
            <div class="mt-2 whitespace-pre-line">{{ $inquiry->answer }}</div>
        </section>
    @else
        <p class="mt-4 rounded-2xl bg-paper px-4 py-3 text-sm text-mist">답변을 준비하고 있어요. 답변이 달리면 문자로 알려 드려요.</p>
    @endif
@endsection
