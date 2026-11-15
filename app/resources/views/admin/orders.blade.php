@extends('layouts.backoffice')
@section('title', '전체 주문')
@section('eyebrow', '사이렌오더')

@section('content')
    <form method="GET" class="mb-5 flex flex-wrap items-end gap-3 rounded-3xl bg-white p-4 ring-1 ring-ink/5">
        <div>
            <label for="vendor" class="label">업체</label>
            <select id="vendor" name="vendor" class="field !py-2.5">
                <option value="">전체 업체</option>
                @foreach ($vendors as $v)
                    <option value="{{ $v->id }}" @selected((int) request('vendor') === $v->id)>{{ $v->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="group" class="label">상태</label>
            <select id="group" name="group" class="field !py-2.5">
                @foreach (['' => '전체', 'active' => '진행 중', 'done' => '수령·정산 완료', 'cancelled' => '취소·환불'] as $v => $l)
                    <option value="{{ $v }}" @selected(request('group', '') === $v)>{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="from" class="label">주문일 시작</label>
            <input id="from" type="date" name="from" value="{{ $from }}" class="field num !py-2.5">
        </div>
        <div>
            <label for="to" class="label">끝</label>
            <input id="to" type="date" name="to" value="{{ $to }}" class="field num !py-2.5">
        </div>
        <button class="btn-ink !py-3">조회</button>
        <div class="ml-auto text-right">
            <p class="text-xs text-mist">{{ number_format($count) }}건 · 취소 제외 매출</p>
            <p class="num text-2xl font-black">{{ number_format($total) }}<span class="font-sans text-sm font-semibold">원</span></p>
        </div>
    </form>

    @include('backoffice.orders-table', ['orders' => $orders, 'showVendor' => true, 'mode' => request('group') === 'cancelled' ? 'cancels' : 'all'])
@endsection
