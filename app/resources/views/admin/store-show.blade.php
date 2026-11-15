@extends('layouts.backoffice')
@section('title', $store->name)
@section('eyebrow', ($store->vendor?->name ?? '업체 미지정').' · '.$store->code)

@php($slotNames = ['pre' => '공연 전', 'intermission' => '인터미션', 'post' => '공연 후'])

@section('actions')
    <a href="{{ route('display', $store) }}" target="_blank" class="btn-ghost !py-2.5 text-sm">픽업 전광판</a>
    <a href="{{ route('admin.stores.edit', $store->id) }}" class="btn-ink !py-2.5 text-sm">매장 수정</a>
@endsection

@section('content')
    <div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-3xl bg-ink p-5 text-paper">
            <p class="text-xs text-paper/60">판매 ({{ \Illuminate\Support\Carbon::parse($from)->format('m.d') }}–{{ \Illuminate\Support\Carbon::parse($to)->format('m.d') }})</p>
            <p class="num mt-1 text-2xl font-black">{{ number_format($salesAmount) }}<span class="font-sans text-sm">원</span></p>
            <p class="text-xs text-paper/60"><span class="num">{{ $salesCount }}</span>건</p>
        </div>
        <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/5">
            <p class="text-xs text-mist">취소·환불</p>
            <p class="num mt-1 text-2xl font-black">{{ number_format($cancelAmount) }}<span class="font-sans text-sm">원</span></p>
            <p class="text-xs text-mist"><span class="num">{{ $cancelCount }}</span>건</p>
        </div>
        <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/5">
            <p class="text-xs text-mist">정산 대기</p>
            <p class="num mt-1 text-2xl font-black">{{ number_format($unsettledAmount) }}<span class="font-sans text-sm">원</span></p>
            <p class="text-xs text-mist">수수료 {{ rtrim(rtrim($store->commission_rate, '0'), '.') }}% + 부가세</p>
        </div>
        <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/5">
            <p class="text-xs text-mist">상태</p>
            <p class="mt-1 text-xl font-black">{{ $store->is_open ? '영업 중' : '영업 종료' }}</p>
            <p class="text-xs text-mist">업체 {{ $store->vendor?->stateLabel() ?? '—' }}</p>
        </div>
    </div>

    @if ($event)
        <section class="mb-5 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
            <h2 class="text-sm font-bold">{{ $event->starts_at->format('m.d') }} {{ $event->artist }} — 주문 시간대</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                @foreach ($slots as $s)
                    <div class="rounded-2xl bg-paper p-4">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold">{{ $slotNames[$s->slot] }}</span>
                            <span @class(['chip', 'bg-stick/10 text-stick' => $s->isOpen(), 'bg-signal/10 text-signal' => ! $s->isOpen()])>{{ $s->is_closed ? '매장 마감' : ($s->isOpen() ? '받는 중' : '마감') }}</span>
                        </div>
                        <p class="num mt-2 text-lg font-bold">{{ $s->used }}<span class="text-sm text-mist">/{{ $s->capacity }}</span></p>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-paper-deep"><div class="h-full rounded-full bg-stick" style="width: {{ $s->capacity ? min(100, round($s->used / $s->capacity * 100)) : 0 }}%"></div></div>
                        <p class="num mt-1 text-xs text-mist">마감 {{ $s->closes_at?->format('H:i') ?? '—' }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- 현장 카드 단말기 (준비 단계) --}}
    <section class="mb-5 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
        <h2 class="text-sm font-bold">현장 카드 단말기 <span class="font-normal text-mist">· POS 카드결제에 단말기·승인번호를 남겨 단말기 매출과 대사해요</span></h2>
        <ul class="mt-2 flex flex-wrap gap-2 text-sm">
            @forelse ($terminals as $t)
                <li class="chip bg-paper">{{ $t->provider }} · <span class="num">{{ $t->tid }}</span>{{ $t->label ? ' · '.$t->label : '' }}</li>
            @empty
                <li class="text-mist">등록된 단말기가 없어요.</li>
            @endforelse
        </ul>
        <form method="POST" action="{{ route('admin.stores.terminals', $store->id) }}" class="mt-3 flex flex-wrap gap-2">
            @csrf
            <input name="provider" class="field !w-32 !py-2" placeholder="밴사 (KSNET 등)" required aria-label="밴사">
            <input name="tid" class="field num !w-40 !py-2" placeholder="단말기 ID (TID)" required aria-label="단말기 ID">
            <input name="label" class="field !w-40 !py-2" placeholder="이름 (1번 카운터)" aria-label="단말기 이름">
            <button class="btn-ink !py-2 text-sm">단말기 등록</button>
        </form>
        @error('tid')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
    </section>

    <section class="mb-6" x-data="{ blocking: null }">
        <h2 class="mb-3 text-lg font-bold">상품 <span class="num text-mist">{{ $store->products->count() }}</span></h2>
        <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
            <table class="w-full min-w-[760px] text-sm">
                <thead class="border-b border-ink/5 text-left text-xs text-mist">
                    <tr><th class="px-5 py-3 font-semibold">상품</th><th class="px-3 py-3 text-right font-semibold">가격</th><th class="px-3 py-3 font-semibold">재고</th><th class="px-3 py-3 text-right font-semibold">판매</th><th class="px-5 py-3 font-semibold">운영사 조치</th></tr>
                </thead>
                <tbody class="divide-y divide-ink/5">
                    @forelse ($store->products as $p)
                        <tr @class(['opacity-60' => ! $p->is_visible])>
                            <td class="px-5 py-3">
                                <b>{{ $p->name }}</b>
                                @if ($p->is_alcohol)<span class="chip ml-1 bg-signal/10 text-signal">주류</span>@endif
                                @unless ($p->is_visible)<span class="chip ml-1 bg-ink/10">업체 판매중지</span>@endunless
                                @if ($p->isBlocked())<p class="mt-1 text-xs font-semibold text-signal">운영사 판매 중지 · {{ $p->block_reason }}</p>@endif
                            </td>
                            <td class="num px-3 py-3 text-right">{{ number_format($p->price) }}</td>
                            <td class="px-3 py-3 text-xs">
                                @if ($p->options->isEmpty()) <span class="num">{{ $p->stock ?? '무제한' }}</span>
                                @else {{ $p->options->map(fn ($o) => $o->name.' '.($o->stock ?? '∞'))->join(' · ') }} @endif
                            </td>
                            <td class="num px-3 py-3 text-right">{{ number_format($sold[$p->id] ?? 0) }}</td>
                            <td class="px-5 py-3">
                                @if ($p->isBlocked())
                                    <form method="POST" action="{{ route('admin.products.block', $p) }}">@csrf @method('PATCH')<button class="btn-ghost !px-3 !py-1.5 text-xs">판매 중지 해제</button></form>
                                @else
                                    <button type="button" @click="blocking = blocking === {{ $p->id }} ? null : {{ $p->id }}" class="btn-ghost !px-3 !py-1.5 text-xs text-signal">판매 중지</button>
                                    <form x-show="blocking === {{ $p->id }}" x-cloak method="POST" action="{{ route('admin.products.block', $p) }}" class="mt-2 flex gap-2">
                                        @csrf @method('PATCH')
                                        <input name="reason" class="field !py-1.5 text-xs" placeholder="사유 (업체에 보여요)" required aria-label="{{ $p->name }} 판매 중지 사유">
                                        <button class="btn shrink-0 bg-signal !px-3 !py-1.5 text-xs text-white">중지</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-8 text-center text-mist">등록된 상품이 없어요.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <h2 class="mb-3 text-lg font-bold">주문 내역</h2>
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded-3xl bg-white p-4 ring-1 ring-ink/5">
        <div>
            <label for="group" class="label">상태</label>
            <select id="group" name="group" class="field !py-2.5">
                @foreach (['' => '전체', 'active' => '진행 중', 'done' => '수령·정산 완료', 'cancelled' => '취소·환불'] as $v => $l)
                    <option value="{{ $v }}" @selected(request('group', '') === $v)>{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div><label for="from" class="label">주문일 시작</label><input id="from" type="date" name="from" value="{{ $from }}" class="field num !py-2.5"></div>
        <div><label for="to" class="label">끝</label><input id="to" type="date" name="to" value="{{ $to }}" class="field num !py-2.5"></div>
        <button class="btn-ink !py-3">조회</button>
    </form>
    @include('backoffice.orders-table', ['orders' => $orders, 'mode' => request('group') === 'cancelled' ? 'cancels' : 'all'])
@endsection
