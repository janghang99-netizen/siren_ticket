@extends('layouts.backoffice')
@section('title', '상품')
@section('eyebrow', $store->name)

@section('actions')
    <a href="{{ route('partner.products.create') }}" class="btn-ink !py-2.5 text-sm">상품 등록</a>
@endsection

@section('content')
    @if ($products->isEmpty())
        <div class="rounded-3xl bg-white px-6 py-12 text-center ring-1 ring-ink/5">
            <p class="text-lg font-bold">{{ $store->name }}에 등록된 상품이 없어요</p>
            <p class="mt-1 text-sm text-mist">상품을 등록하면 팬들이 공연장에서 바로 주문할 수 있어요.</p>
            <a href="{{ route('partner.products.create') }}" class="btn-ink mt-5">첫 상품 등록</a>
        </div>
    @else
        <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
            <table class="w-full min-w-[720px] text-sm">
                <thead class="border-b border-ink/5 text-left text-xs text-mist">
                    <tr>
                        <th class="px-5 py-3 font-semibold">상품</th>
                        <th class="px-3 py-3 text-right font-semibold">가격</th>
                        <th class="px-3 py-3 font-semibold">옵션 · 재고</th>
                        <th class="px-3 py-3 text-right font-semibold">판매 수량</th>
                        <th class="px-3 py-3 font-semibold">상태</th>
                        <th class="px-5 py-3"><span class="sr-only">관리</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink/5">
                    @foreach ($products as $p)
                        @php($soldOut = $p->options->isEmpty() ? ($p->stock !== null && $p->stock <= 0) : $p->options->every(fn ($o) => $o->stock !== null && $o->stock <= 0))
                        <tr @class(['opacity-50' => ! $p->is_visible])>
                            <td class="px-5 py-4">
                                <p class="font-bold">{{ $p->name }}</p>
                                <p class="text-xs text-mist">재고 차감: {{ $p->stock_deduct_at === 'order' ? '주문할 때' : '결제할 때' }}</p>
                            </td>
                            <td class="num px-3 py-4 text-right font-bold">{{ number_format($p->price) }}</td>
                            <td class="px-3 py-4">
                                @if ($p->options->isEmpty())
                                    <span class="text-mist">재고</span> <span class="num font-bold">{{ $p->stock ?? '무제한' }}</span>
                                @else
                                    <ul class="flex flex-wrap gap-1.5">
                                        @foreach ($p->options as $o)
                                            <li @class(['chip ring-1 ring-ink/10', 'text-signal' => $o->stock !== null && $o->stock <= 0])>
                                                {{ $o->name }}@if ($o->extra_price) <span class="text-mist">+{{ number_format($o->extra_price) }}</span>@endif
                                                · <span class="num">{{ $o->stock ?? '∞' }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                            <td class="num px-3 py-4 text-right">{{ number_format($sold[$p->id] ?? 0) }}</td>
                            <td class="px-3 py-4">
                                @if (! $p->is_visible)
                                    <span class="chip bg-ink/10">판매 중지</span>
                                @elseif ($soldOut)
                                    <span class="chip bg-signal/10 text-signal">품절</span>
                                @else
                                    <span class="chip bg-stick/10 text-stick">판매 중</span>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('partner.products.edit', $p) }}" class="btn-ghost !px-3 !py-2 text-xs">수정</a>
                                    <form method="POST" action="{{ route('partner.products.toggle', $p) }}">
                                        @csrf @method('PATCH')
                                        <button class="btn-ghost !px-3 !py-2 text-xs">{{ $p->is_visible ? '판매 중지' : '다시 판매' }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
