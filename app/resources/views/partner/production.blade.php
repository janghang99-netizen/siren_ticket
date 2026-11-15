@extends('layouts.backoffice')
@section('title', '생산관리')
@section('eyebrow', $store->name.' · 오늘 생산량으로 재고를 맞춰요')

@section('content')
    <form method="POST" action="{{ route('partner.production.save') }}" class="mb-8">
        @csrf
        <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="border-b border-ink/5 text-left text-xs text-mist">
                    <tr><th class="px-5 py-3 font-semibold">품목</th><th class="px-3 py-3 text-right font-semibold">현재 재고</th><th class="px-3 py-3 font-semibold">오늘 생산량</th><th class="px-5 py-3 font-semibold">비고</th></tr>
                </thead>
                <tbody class="divide-y divide-ink/5">
                    @foreach ($products as $p)
                        @foreach ($p->options->isEmpty() ? [null] : $p->options as $o)
                            @php($key = $p->id.':'.($o?->id ?? ''))
                            <tr>
                                <td class="px-5 py-2.5"><b>{{ $p->name }}</b>@if ($o)<span class="text-mist"> · {{ $o->name }}</span>@endif</td>
                                <td class="num px-3 py-2.5 text-right">{{ ($o && $o->stock !== null ? $o->stock : $p->stock) ?? '무제한' }}</td>
                                <td class="px-3 py-2.5"><input name="qty[{{ $key }}]" type="number" min="0" class="field num !w-28 !py-2" placeholder="수량" aria-label="{{ $p->name }} 생산량"></td>
                                <td class="px-5 py-2.5"><input name="memo[{{ $key }}]" class="field !py-2" placeholder="예: 오전 생산" aria-label="비고"></td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-mist">생산일자는 항상 오늘(<span class="num">{{ now()->format('Y.m.d') }}</span>)로 저장돼요. 재고는 입력한 수량으로 바뀌고(누적 아님), 업체 EIS에 생산 내역이 전송돼요. 빈칸은 건너뛰고 0은 재고 0이에요.</p>
            <button class="btn-ink">생산 저장</button>
        </div>
    </form>

    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
        <h2 class="text-lg font-bold">생산 기록</h2>
        <form method="GET" class="flex items-end gap-2"><input type="date" name="date" value="{{ $date }}" class="field num !py-2" aria-label="생산일"><button class="btn-ghost !py-2 text-sm">보기</button></form>
    </div>
    @if ($records->isEmpty())
        <div class="rounded-3xl bg-white px-6 py-10 text-center text-sm text-mist ring-1 ring-ink/5">이 날짜에 생산 기록이 없어요.</div>
    @else
        <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
            <table class="w-full min-w-[720px] text-sm">
                <thead class="border-b border-ink/5 text-left text-xs text-mist">
                    <tr><th class="px-5 py-3 font-semibold">시각</th><th class="px-3 py-3 font-semibold">품목</th><th class="px-3 py-3 font-semibold">수량 · 비고</th><th class="px-3 py-3 font-semibold">EIS</th><th class="px-5 py-3"></th></tr>
                </thead>
                <tbody class="divide-y divide-ink/5">
                    @foreach ($records as $r)
                        <tr>
                            <td class="num px-5 py-2.5 text-xs">{{ \Illuminate\Support\Carbon::parse($r->created_at)->format('H:i') }}</td>
                            <td class="px-3 py-2.5">{{ $r->product_name }}@if ($r->option_name)<span class="text-mist"> · {{ $r->option_name }}</span>@endif</td>
                            <td class="px-3 py-2.5">
                                <form method="POST" action="{{ route('partner.production.update', $r->id) }}" class="flex gap-2">
                                    @csrf @method('PUT')
                                    <input name="qty" type="number" min="0" value="{{ $r->qty }}" class="field num !w-24 !py-1.5" aria-label="수량">
                                    <input name="memo" value="{{ $r->memo }}" class="field !py-1.5" aria-label="비고">
                                    <button class="btn-ghost !px-3 !py-1.5 text-xs">수정</button>
                                </form>
                            </td>
                            <td class="px-3 py-2.5 text-xs">{{ $r->webhook_sent ? '전송됨' : '미전송' }}</td>
                            <td class="px-5 py-2.5">
                                <form method="POST" action="{{ route('partner.production.delete', $r->id) }}" onsubmit="return confirm('이 생산 기록을 지울까요? 재고는 바뀌지 않아요.')">
                                    @csrf @method('DELETE')<button class="text-xs font-semibold text-signal">삭제</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
