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
    @if ($card && $card['ok'])
        {{-- 카드결제(PG) 기준 정산금액 — 페이레터를 불러온 업체만. 표시만: 지급액·지급 처리는 아래 정산서 그대로 (원본 jungsan_list.php) --}}
        <div class="mb-4 flex flex-wrap items-start justify-between gap-4 rounded-3xl bg-pick/40 p-5 ring-1 ring-ink/5">
            <div>
                <p class="text-xs font-semibold text-mist">카드결제 기준 정산금액 (실제 송금 기준)</p>
                <p class="num mt-1 text-2xl font-black">{{ number_format($card['pay']) }}<span class="font-sans text-sm font-semibold">원</span></p>
                <p class="mt-1 text-xs text-mist">현금·계좌이체는 매장 직접 수령이라 빠져요. 세금계산서(이용료) 공급가 <span class="num">{{ number_format($card['fee']) }}</span> · 세액 <span class="num">{{ number_format($card['vat']) }}</span></p>
            </div>
            <div class="min-w-[260px]">@include('admin.partials.card-note', ['c' => $card, 'vendor' => $settlement->vendor, 'old' => $settlement->payout_amount, 'src' => 'done'])</div>
        </div>
    @endif
    @include('backoffice.settlement-detail')
@endsection
