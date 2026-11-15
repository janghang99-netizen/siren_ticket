@extends('layouts.backoffice')
@section('title', $settlement->vendor->name.' 정산서')
@section('eyebrow', '정산 #'.$settlement->id)

@section('actions')
    @if ($settlement->status !== 'paid')
        <form method="POST" action="{{ route('admin.settlements.pay', $settlement) }}"
              onsubmit="return confirm('{{ $settlement->vendor->name }}에 {{ number_format($settlement->payout_amount) }}원을 보냈나요? 지급 완료로 표시합니다.')">
            @csrf @method('PATCH')
            <button class="btn-ink !py-2.5 text-sm">지급 완료로 표시</button>
        </form>
    @endif
    <a href="{{ route('admin.settlements.index') }}" class="btn-ghost !py-2.5 text-sm">정산 목록</a>
@endsection

@section('content')
    @include('backoffice.settlement-detail')
@endsection
