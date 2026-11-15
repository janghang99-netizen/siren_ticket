@extends('layouts.backoffice')
@section('title', '정산 내역')
@section('eyebrow', $vendor->name)

@section('content')
    <div class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <div class="rounded-3xl bg-ink p-5 text-paper">
            <p class="text-xs font-semibold text-paper/60">다음 정산 예정 (수령완료, 정산 전)</p>
            <p class="num mt-1 text-2xl font-black">{{ number_format($pendingAmount) }}<span class="font-sans text-sm">원</span></p>
            <p class="mt-1 text-xs text-paper/60"><span class="num">{{ $pendingCount }}</span>건 · 수수료를 빼고 지급돼요</p>
        </div>
        <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/5">
            <p class="text-xs font-semibold text-mist">정산 계좌</p>
            <p class="mt-1 font-bold">{{ $vendor->bank_name ?? '등록 안 됨' }} {{ $vendor->bank_account }}</p>
            <p class="text-xs text-mist">{{ $vendor->account_holder }} · 바꾸려면 운영사에 요청해 주세요</p>
        </div>
    </div>

    @include('backoffice.settlement-list', ['settlements' => $settlements, 'showVendor' => false, 'route' => 'partner.settlements.show'])
@endsection
