@extends('layouts.backoffice')
@section('title', $title)
@section('eyebrow', $vendor->name.' 전체 매장')

@section('content')
    <form method="GET" class="mb-5 flex flex-wrap items-end gap-3 rounded-3xl bg-white p-4 ring-1 ring-ink/5">
        <div>
            <label for="store" class="label">매장</label>
            <select id="store" name="store" class="field !py-2.5">
                <option value="">전체 매장</option>
                @foreach ($stores as $s)
                    <option value="{{ $s->id }}" @selected((int) request('store') === $s->id)>{{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="from" class="label">{{ $mode === 'cancels' ? '취소일' : '주문일' }} 시작</label>
            <input id="from" type="date" name="from" value="{{ $from }}" class="field num !py-2.5">
        </div>
        <div>
            <label for="to" class="label">끝</label>
            <input id="to" type="date" name="to" value="{{ $to }}" class="field num !py-2.5">
        </div>
        <button class="btn-ink !py-3">조회</button>

        <div class="ml-auto text-right">
            <p class="text-xs text-mist">{{ $mode === 'cancels' ? '취소' : '판매' }} {{ number_format($count) }}건</p>
            <p class="num text-2xl font-black">{{ number_format($total) }}<span class="font-sans text-sm font-semibold">원</span></p>
        </div>
    </form>

    @include('backoffice.orders-table', ['orders' => $orders, 'mode' => $mode])
@endsection
