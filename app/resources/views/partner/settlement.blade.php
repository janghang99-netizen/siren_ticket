@extends('layouts.backoffice')
@section('title', '정산서 #'.$settlement->id)
@section('eyebrow', $vendor->name)

@section('actions')
    <a href="{{ route('partner.settlements') }}" class="btn-ghost !py-2.5 text-sm">정산 내역</a>
@endsection

@if ($settlement->canDispute())
    @push('settlement-actions')
        <details class="mt-4 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
            <summary class="cursor-pointer font-bold">정산 내역에 이의가 있어요</summary>
            <form method="POST" action="{{ route('partner.settlements.dispute', $settlement) }}" class="mt-3">
                @csrf
                <p class="text-sm text-mist">정산서 발행일부터 30일 안에 한 번 이의를 제기할 수 있어요 ({{ $settlement->created_at->copy()->addDays(30)->format('m.d') }}까지). 운영사가 확인하고 여기에 답변해요.</p>
                <textarea name="message" rows="3" class="field mt-3" placeholder="어떤 주문·금액이 왜 다른지 적어 주세요" required>{{ old('message') }}</textarea>
                @error('message')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
                <button class="btn-ink mt-3 !py-2.5 text-sm">이의제기 보내기</button>
            </form>
        </details>
    @endpush
@endif

@section('content')
    @include('backoffice.settlement-detail')
@endsection
