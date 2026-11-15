@extends('layouts.backoffice')
@section('title', '생산 · 재고')
@section('eyebrow', $store->name)

@php($types = \App\Models\StockMovement::TYPES)

@section('content')
    {{-- 품목별 현재 재고 + 빠른 입력 --}}
    <section class="mb-8">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
            <h2 class="text-lg font-bold">현재 재고</h2>
            <p class="text-xs text-mist">생산은 더하고, 폐기는 빼고, 재고 맞춤은 실제 센 수량으로 바꿔요 (당일 리셋도 재고 맞춤으로).</p>
        </div>
        @error('qty')<p class="mb-3 rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal">{{ $message }}</p>@enderror

        <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
            <table class="w-full min-w-[820px] text-sm">
                <thead class="border-b border-ink/5 text-left text-xs text-mist">
                    <tr><th class="px-5 py-3 font-semibold">품목</th><th class="px-3 py-3 text-right font-semibold">현재 재고</th><th class="px-5 py-3 font-semibold">입력</th></tr>
                </thead>
                <tbody class="divide-y divide-ink/5">
                    @forelse ($products as $p)
                        @php($rows = $p->options->isEmpty() ? [[null, $p->stock]] : $p->options->map(fn ($o) => [$o, $o->stock])->all())
                        @foreach ($rows as [$opt, $stock])
                            <tr x-data="{ type: 'produce' }">
                                <td class="px-5 py-3">
                                    <b>{{ $p->name }}</b>@if ($opt)<span class="text-mist"> · {{ $opt->name }}</span>@endif
                                    @unless ($p->is_visible)<span class="chip ml-1 bg-ink/10">판매 중지</span>@endunless
                                </td>
                                <td class="px-3 py-3 text-right">
                                    @if ($stock === null)
                                        <span class="text-xs text-mist">관리 안 함(무제한)</span>
                                    @else
                                        <span @class(['num text-lg font-black', 'text-signal' => $stock <= 0, 'text-ink' => $stock > 0])>{{ number_format($stock) }}</span>
                                        @if ($stock <= 0)<span class="block text-xs font-semibold text-signal">품절</span>@elseif ($stock < 20)<span class="block text-xs text-signal">곧 품절</span>@endif
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <form method="POST" action="{{ route('partner.inventory.record') }}" class="flex flex-wrap items-center gap-2">
                                        @csrf
                                        <input type="hidden" name="product_id" value="{{ $p->id }}">
                                        @if ($opt)<input type="hidden" name="option_id" value="{{ $opt->id }}">@endif
                                        <div class="flex rounded-full bg-paper p-1 text-xs font-semibold" role="radiogroup" aria-label="{{ $p->name }} 작업">
                                            @foreach (['produce' => '생산 +', 'discard' => '폐기 −', 'adjust' => '재고 맞춤 ='] as $k => $l)
                                                <label class="cursor-pointer rounded-full px-3 py-1.5" :class="type === '{{ $k }}' ? 'bg-ink text-paper' : 'text-mist'">
                                                    <input type="radio" name="type" value="{{ $k }}" x-model="type" class="sr-only">{{ $l }}
                                                </label>
                                            @endforeach
                                        </div>
                                        <input name="qty" type="number" min="0" class="field num !w-24 !py-2" placeholder="수량" required aria-label="수량">
                                        <input name="memo" class="field !w-44 !py-2" :placeholder="type === 'adjust' ? '예: 당일 리셋, 실사' : (type === 'discard' ? '예: 유통기한' : '예: 오전 생산')" aria-label="메모">
                                        <button class="btn-ink !px-4 !py-2 text-sm">기록</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    @empty
                        <tr><td colspan="3" class="px-5 py-8 text-center text-mist">상품을 먼저 등록해 주세요.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- 재고 원장 --}}
    <section>
        <h2 class="mb-3 text-lg font-bold">생산 · 재고 내역</h2>
        <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded-3xl bg-white p-4 ring-1 ring-ink/5">
            <div>
                <label for="type" class="label">구분</label>
                <select id="type" name="type" class="field !py-2.5">
                    <option value="">전체</option>
                    @foreach ($types as $k => $l)<option value="{{ $k }}" @selected($type === $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div><label for="from" class="label">시작</label><input id="from" type="date" name="from" value="{{ $from }}" class="field num !py-2.5"></div>
            <div><label for="to" class="label">끝</label><input id="to" type="date" name="to" value="{{ $to }}" class="field num !py-2.5"></div>
            <button class="btn-ink !py-3">조회</button>
            <div class="ml-auto flex flex-wrap gap-4 text-right">
                @foreach (['produce' => '생산', 'sale' => '판매', 'discard' => '폐기'] as $k => $l)
                    <div><p class="text-xs text-mist">{{ $l }}</p><p class="num text-xl font-black">{{ number_format(abs((int) ($totals[$k] ?? 0))) }}</p></div>
                @endforeach
            </div>
        </form>

        @if ($movements->isEmpty())
            <div class="rounded-3xl bg-white px-6 py-12 text-center ring-1 ring-ink/5"><p class="font-bold">이 기간에 재고 변동이 없어요</p></div>
        @else
            <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="border-b border-ink/5 text-left text-xs text-mist">
                        <tr>
                            <th class="px-5 py-3 font-semibold">일시</th><th class="px-3 py-3 font-semibold">구분</th><th class="px-3 py-3 font-semibold">품목</th>
                            <th class="px-3 py-3 text-right font-semibold">수량</th><th class="px-3 py-3 text-right font-semibold">변동 후</th><th class="px-5 py-3 font-semibold">메모 · 주문</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink/5">
                        @foreach ($movements as $m)
                            <tr @if ($m->order) data-href="{{ route('partner.orders.show', $m->order) }}" @endif>
                                <td class="num px-5 py-2.5 text-xs">{{ $m->created_at->format('m.d H:i') }}</td>
                                <td class="px-3 py-2.5"><span @class(['chip',
                                    'bg-stick/10 text-stick' => $m->type === 'produce',
                                    'bg-signal/10 text-signal' => $m->type === 'discard',
                                    'bg-pick' => $m->type === 'adjust',
                                    'bg-paper' => in_array($m->type, ['sale', 'restore'])])>{{ $m->typeLabel() }}</span></td>
                                <td class="px-3 py-2.5">{{ $m->product->name }}@if ($m->option)<span class="text-mist"> · {{ $m->option->name }}</span>@endif</td>
                                <td @class(['num px-3 py-2.5 text-right font-bold', 'text-signal' => $m->qty < 0, 'text-stick' => $m->qty > 0])>{{ $m->qty > 0 ? '+' : '' }}{{ number_format($m->qty) }}</td>
                                <td class="num px-3 py-2.5 text-right">{{ $m->stock_after !== null ? number_format($m->stock_after) : '—' }}</td>
                                <td class="px-5 py-2.5 text-xs">
                                    @if ($m->order)<a href="{{ route('partner.orders.show', $m->order) }}" class="row-link num">주문 {{ $m->order->pickup_code }}</a>@endif
                                    {{ $m->memo }} @if ($m->user)<span class="text-mist">· {{ $m->user->name }}</span>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $movements->links() }}</div>
        @endif
    </section>
@endsection
