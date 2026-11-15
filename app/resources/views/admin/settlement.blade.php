@extends('layouts.backoffice')
@section('title', $settlement->vendor->name.' 정산서')
@section('eyebrow', '정산 #'.$settlement->id)

@section('actions')
    <form method="POST" action="{{ route('admin.settlements.tax-invoice', $settlement) }}">
        @csrf @method('PATCH')
        <button class="btn-ghost !py-2.5 text-sm">{{ $settlement->tax_invoice_issued_at ? '세금계산서 발행 표시 지우기' : '세금계산서 발행 완료로 표시' }}</button>
    </form>
    @if ($settlement->status !== 'paid')
        <form method="POST" action="{{ route('admin.settlements.pay', $settlement) }}"
              onsubmit="return confirm('{{ $settlement->vendor->name }}에 {{ number_format($settlement->payout_amount) }}원을 보냈나요? 지급 완료로 표시합니다.')">
            @csrf @method('PATCH')
            <button class="btn-ink !py-2.5 text-sm">지급 완료로 표시</button>
        </form>
    @endif
    <a href="{{ route('admin.vendors.show', $settlement->vendor) }}" class="btn-ghost !py-2.5 text-sm">업체 보기</a>
@endsection

@if ($settlement->disputed_at && ! $settlement->dispute_replied_at)
    @push('settlement-actions')
        <form method="POST" action="{{ route('admin.settlements.reply', $settlement) }}" class="mt-4 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
            @csrf
            <label for="reply" class="label">이의제기 답변</label>
            <textarea id="reply" name="reply" rows="3" class="field" placeholder="확인 결과와 조치(재정산·공제 조정 등)를 적어 주세요" required>{{ old('reply') }}</textarea>
            @error('reply')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            <button class="btn-ink mt-3 !py-2.5 text-sm">답변 보내기</button>
        </form>
    @endpush
@endif

@section('content')
    @include('backoffice.settlement-detail')
@endsection
