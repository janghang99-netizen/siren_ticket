@extends('layouts.backoffice')
@section('title', '정산')
@section('eyebrow', '입점업체')

@section('content')
    <div class="mb-6 grid gap-4 xl:grid-cols-[1fr_1.4fr]">
        <div class="rounded-3xl bg-ink p-6 text-paper">
            <p class="text-xs font-semibold text-paper/60">정산 대기 (수령완료, 정산서 없음)</p>
            <p class="num mt-1 text-3xl font-black">{{ number_format($unsettledAmount) }}<span class="font-sans text-base">원</span></p>
            <p class="mt-1 text-sm text-paper/60"><span class="num">{{ number_format($unsettledCount) }}</span>건{{ $oldest ? ' · 가장 오래된 수령 '.\Illuminate\Support\Carbon::parse($oldest)->format('m.d') : '' }}</p>
        </div>

        <form method="POST" action="{{ route('admin.settlements.generate') }}" class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            @csrf
            <h2 class="font-bold">정산서 만들기</h2>
            <p class="mt-1 text-sm text-mist">기간 안에 수령완료된 주문을 업체별로 묶어요. 묶인 주문은 정산완료로 바뀌고 다시 묶이지 않아요.</p>
            <div class="mt-4 flex flex-wrap items-end gap-3">
                <div>
                    <label for="from" class="label">수령일 시작</label>
                    <input id="from" type="date" name="from" class="field num !py-2.5" value="{{ old('from', now()->startOfWeek()->toDateString()) }}" required>
                </div>
                <div>
                    <label for="to" class="label">끝</label>
                    <input id="to" type="date" name="to" class="field num !py-2.5" value="{{ old('to', now()->toDateString()) }}" required>
                </div>
                <button class="btn-ink !py-3" onclick="return confirm('이 기간의 정산서를 만들까요? 만든 뒤에는 주문이 정산완료로 바뀌어요.')">정산서 만들기</button>
            </div>
            @error('from')<p class="mt-2 text-sm text-signal">{{ $message }}</p>@enderror
        </form>
    </div>

    @include('backoffice.settlement-list', ['settlements' => $settlements, 'showVendor' => true, 'route' => 'admin.settlements.show'])
@endsection
