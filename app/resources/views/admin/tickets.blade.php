@extends('layouts.backoffice')
@section('title', '티켓 판매')
@section('eyebrow', '좌석티켓')

@section('content')
    <form method="GET" class="mb-5 flex flex-wrap items-end gap-3 rounded-3xl bg-white p-4 ring-1 ring-ink/5">
        <div>
            <label for="event" class="label">공연</label>
            <select id="event" name="event" class="field !py-2.5">
                <option value="">전체 공연</option>
                @foreach ($events as $e)
                    <option value="{{ $e->id }}" @selected((int) request('event') === $e->id)>{{ $e->starts_at->format('m.d') }} {{ $e->artist }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="status" class="label">상태</label>
            <select id="status" name="status" class="field !py-2.5">
                <option value="">전체</option>
                @foreach (['paid' => '예매 완료', 'used' => '입장 완료', 'cancelled' => '취소'] as $v => $l)
                    <option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="q" class="label">예매자 · 번호</label>
            <input id="q" name="q" value="{{ request('q') }}" class="field !py-2.5" placeholder="이름, 휴대폰, 티켓번호">
        </div>
        <button class="btn-ink !py-3">조회</button>
        <div class="ml-auto text-right">
            <p class="text-xs text-mist">{{ number_format($count) }}매 · 취소 제외 매출</p>
            <p class="num text-2xl font-black">{{ number_format($revenue) }}<span class="font-sans text-sm font-semibold">원</span></p>
        </div>
    </form>

    @if ($tickets->isEmpty())
        <div class="rounded-3xl bg-white px-6 py-12 text-center ring-1 ring-ink/5"><p class="font-bold">조건에 맞는 티켓이 없어요</p></div>
    @else
        <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
            <table class="w-full min-w-[860px] text-sm">
                <thead class="border-b border-ink/5 text-left text-xs text-mist">
                    <tr>
                        <th class="px-5 py-3 font-semibold">예매 일시</th>
                        <th class="px-3 py-3 font-semibold">공연</th>
                        <th class="px-3 py-3 font-semibold">좌석</th>
                        <th class="px-3 py-3 font-semibold">예매자</th>
                        <th class="px-3 py-3 font-semibold">티켓번호</th>
                        <th class="px-3 py-3 text-right font-semibold">금액</th>
                        <th class="px-5 py-3 font-semibold">상태</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink/5">
                    @foreach ($tickets as $t)
                        <tr>
                            <td class="num px-5 py-3 text-xs">{{ $t->paid_at?->format('m.d H:i') }}</td>
                            <td class="px-3 py-3">{{ $t->event->artist }} <span class="num text-xs text-mist">{{ $t->event->starts_at->format('m.d') }}</span></td>
                            <td class="px-3 py-3">{{ $t->seatLabel() }} <span class="text-xs text-mist">{{ $t->section->tier }}</span></td>
                            <td class="px-3 py-3">{{ $t->holder_name }} <span class="num block text-xs text-mist">{{ preg_replace('/(\d{3})(\d{3,4})(\d{4})/', '$1-****-$3', $t->holder_phone) }}</span></td>
                            <td class="num px-3 py-3 text-xs">{{ $t->ticket_no }}</td>
                            <td @class(['num px-3 py-3 text-right font-bold', 'line-through text-mist' => $t->status === 'cancelled'])>{{ number_format($t->price) }}</td>
                            <td class="px-5 py-3">
                                @if ($t->status === 'paid')
                                    <form method="POST" action="{{ route('admin.tickets.cancel', $t) }}" onsubmit="return confirm('{{ $t->ticket_no }} 티켓을 취소하고 좌석을 다시 판매할까요?')" class="flex items-center gap-2">
                                        @csrf @method('PATCH')
                                        <span class="chip bg-stick/10 text-stick">예매 완료</span>
                                        <button class="text-xs font-semibold text-signal hover:underline">취소</button>
                                    </form>
                                @else
                                    <span @class(['chip', 'bg-ink/10' => $t->status === 'used', 'bg-signal/10 text-signal' => $t->status === 'cancelled'])>{{ $t->status === 'used' ? '입장 완료' : '취소' }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $tickets->links() }}</div>
    @endif
@endsection
