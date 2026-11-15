@extends('layouts.backoffice')
@section('title', '상품')
@section('eyebrow', $store->name)

@section('actions')
    <a href="{{ route('partner.products.create') }}" class="btn-ink !py-2.5 text-sm">상품 등록</a>
@endsection

@php
    // 카테고리로 거르기: ?cat={id} · ?cat=none(기타)
    $filter = request('cat');
    $shown = $products->filter(fn ($p) => $filter === null || $filter === ''
        || ($filter === 'none' ? $p->category_id === null : (string) $p->category_id === (string) $filter));
    $groups = $shown->groupBy(fn ($p) => $p->category_id ?? 0);
    $uncategorized = $products->whereNull('category_id')->count();
@endphp

@section('content')
    {{-- 카테고리 관리: 추가 · 이름 · 순서 · 숨기기 · 삭제 --}}
    <details class="mb-5 rounded-3xl bg-white ring-1 ring-ink/5" @if ($categories->isEmpty() || $errors->has('name')) open @endif>
        <summary class="flex cursor-pointer items-center justify-between px-5 py-4 font-bold">
            <span>카테고리 관리 <span class="font-normal text-mist">· {{ $categories->count() }}개 · 손님 메뉴·현장판매에 이 순서로 보여요</span></span>
            <span class="text-xs text-mist">열기 ▾</span>
        </summary>
        <div class="border-t border-ink/5 px-5 py-4">
            <form method="POST" action="{{ route('partner.categories.store') }}" class="mb-4 flex flex-wrap gap-2">
                @csrf
                <label class="min-w-48 flex-1"><span class="sr-only">새 카테고리 이름</span>
                    <input name="name" maxlength="30" required class="field !py-2.5" placeholder="새 카테고리 (예: 커피, 디저트, 음료, 주류)"></label>
                <button class="btn-ink !py-2.5 text-sm">추가</button>
                @error('name')<p class="w-full text-sm font-semibold text-signal">{{ $message }}</p>@enderror
            </form>

            <ul class="divide-y divide-ink/5">
                @forelse ($categories as $c)
                    <li class="flex flex-wrap items-center gap-2 py-2.5">
                        <div class="flex flex-col">
                            <form method="POST" action="{{ route('partner.categories.move', $c) }}">@csrf @method('PATCH')<input type="hidden" name="dir" value="up">
                                <button class="px-2 text-xs text-mist hover:text-ink disabled:opacity-20" @disabled($loop->first) aria-label="{{ $c->name }} 위로">▲</button></form>
                            <form method="POST" action="{{ route('partner.categories.move', $c) }}">@csrf @method('PATCH')<input type="hidden" name="dir" value="down">
                                <button class="px-2 text-xs text-mist hover:text-ink disabled:opacity-20" @disabled($loop->last) aria-label="{{ $c->name }} 아래로">▼</button></form>
                        </div>
                        <form method="POST" action="{{ route('partner.categories.update', $c) }}" class="flex flex-1 flex-wrap items-center gap-2">
                            @csrf @method('PUT')
                            <label class="min-w-40 flex-1"><span class="sr-only">카테고리 이름</span>
                                <input name="name" value="{{ $c->name }}" maxlength="30" required class="field !py-2"></label>
                            <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="is_visible" value="1" @checked($c->is_visible)> 손님에게 보이기</label>
                            <span class="num text-xs text-mist">상품 {{ $c->products_count }}개</span>
                            <button class="btn-ghost !px-3 !py-2 text-xs">저장</button>
                        </form>
                        <form method="POST" action="{{ route('partner.categories.destroy', $c) }}"
                              onsubmit="return confirm(@js('‘'.$c->name.'’ 카테고리를 지울까요?'.($c->products_count ? ' 상품 '.$c->products_count.'개는 지우지 않고 ‘기타’로 옮겨요.' : '')))">
                            @csrf @method('DELETE')
                            <button class="px-2 text-xs font-semibold text-signal">삭제</button>
                        </form>
                        @unless ($c->is_visible)<span class="chip w-full bg-ink/10 text-xs sm:w-auto">숨김 · 이 카테고리 상품은 손님 메뉴·현장판매에 안 보여요</span>@endunless
                    </li>
                @empty
                    <li class="py-3 text-sm text-mist">아직 카테고리가 없어요. 위에서 추가하면 손님 메뉴가 카테고리별로 묶여요.</li>
                @endforelse
            </ul>
        </div>
    </details>

    @if ($products->isEmpty())
        <div class="rounded-3xl bg-white px-6 py-12 text-center ring-1 ring-ink/5">
            <p class="text-lg font-bold">{{ $store->name }}에 등록된 상품이 없어요</p>
            <p class="mt-1 text-sm text-mist">상품을 등록하면 팬들이 공연장에서 바로 주문할 수 있어요.</p>
            <a href="{{ route('partner.products.create') }}" class="btn-ink mt-5">첫 상품 등록</a>
        </div>
    @else
        {{-- 카테고리로 거르기 --}}
        <nav class="mb-3 flex flex-wrap gap-2 text-sm" aria-label="카테고리로 거르기">
            <a href="{{ route('partner.products') }}" @class(['rounded-full px-3 py-1.5 font-semibold ring-1', 'bg-ink text-white ring-ink' => ! $filter, 'bg-white ring-ink/10' => $filter])>전체 <span class="num opacity-70">{{ $products->count() }}</span></a>
            @foreach ($categories as $c)
                <a href="{{ route('partner.products', ['cat' => $c->id]) }}" @class(['rounded-full px-3 py-1.5 font-semibold ring-1', 'bg-ink text-white ring-ink' => (string) $filter === (string) $c->id, 'bg-white ring-ink/10' => (string) $filter !== (string) $c->id])>{{ $c->name }} <span class="num opacity-70">{{ $c->products_count }}</span></a>
            @endforeach
            @if ($uncategorized)
                <a href="{{ route('partner.products', ['cat' => 'none']) }}" @class(['rounded-full px-3 py-1.5 font-semibold ring-1', 'bg-ink text-white ring-ink' => $filter === 'none', 'bg-white ring-ink/10' => $filter !== 'none'])>{{ \App\Models\ProductCategory::UNCATEGORIZED }} <span class="num opacity-70">{{ $uncategorized }}</span></a>
            @endif
        </nav>

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
                @foreach ($groups as $catId => $items)
                    @php($cat = $items->first()->category)
                    <tbody class="divide-y divide-ink/5 border-t border-ink/5">
                        <tr class="bg-paper/60">
                            <th colspan="6" class="px-5 py-2 text-left text-xs font-bold">
                                {{ $cat?->name ?? \App\Models\ProductCategory::UNCATEGORIZED }}
                                <span class="font-normal text-mist">· {{ $items->count() }}개</span>
                                @if ($cat && ! $cat->is_visible)<span class="ml-1 font-semibold text-signal">숨김</span>@endif
                            </th>
                        </tr>
                        @foreach ($items as $p)
                            <tr data-href="{{ route('partner.products.edit', $p) }}" @class(['opacity-50' => ! $p->is_visible || $p->isBlocked() || ($cat && ! $cat->is_visible)])>
                                <td class="px-5 py-4">
                                    <div class="flex items-start gap-3">
                                        @if ($p->imageUrl())
                                            <img src="{{ $p->imageUrl() }}" alt="" class="size-12 shrink-0 rounded-xl object-cover ring-1 ring-ink/5" loading="lazy">
                                        @else
                                            <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-paper text-[10px] text-mist ring-1 ring-ink/5">없음</span>
                                        @endif
                                        <div>
                                            <a href="{{ route('partner.products.edit', $p) }}" class="row-link">{{ $p->name }}</a>
                                            @if ($p->is_alcohol)<span class="chip ml-1 bg-signal/10 text-signal">주류</span>@endif
                                            <p class="text-xs text-mist">재고 차감: {{ $p->stock_deduct_at === 'order' ? '주문할 때' : '결제할 때' }}</p>
                                            @if ($p->isBlocked())<p class="mt-1 text-xs font-semibold text-signal">운영사 판매 중지 · {{ $p->block_reason }}</p>@endif
                                        </div>
                                    </div>
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
                                    @elseif ($p->isSoldOut())
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
                @endforeach
            </table>
        </div>
    @endif
@endsection
