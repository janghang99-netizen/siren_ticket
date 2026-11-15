@extends('layouts.backoffice')
@section('title', $product ? '상품 수정' : '상품 등록')
@section('eyebrow', $store->name)

@php($p = $product)
@php($options = old('options', $p ? $p->options->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'extra_price' => $o->extra_price, 'stock' => $o->stock])->all() : []))

@section('content')
<form method="POST" action="{{ $p ? route('partner.products.update', $p) : route('partner.products.store') }}"
      x-data="{ options: @js(array_values($options)) }"
      class="grid max-w-4xl gap-6 lg:grid-cols-[1fr_320px]">
    @csrf
    @if ($p) @method('PUT') @endif

    <div class="space-y-5 rounded-3xl bg-white p-6 ring-1 ring-ink/5">
        <div>
            <label for="name" class="label">상품명</label>
            <input id="name" name="name" class="field" value="{{ old('name', $p?->name) }}" placeholder="예: 아메리카노" required>
            @error('name')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="price" class="label">기본 가격 (원)</label>
                <input id="price" name="price" type="number" min="0" step="100" class="field num" value="{{ old('price', $p?->price) }}" required>
                @error('price')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="sort" class="label">메뉴 순서</label>
                <input id="sort" name="sort" type="number" min="0" class="field num" value="{{ old('sort', $p?->sort ?? 0) }}">
                <p class="mt-1 text-xs text-mist">작은 숫자가 위에 보여요.</p>
            </div>
        </div>

        <fieldset>
            <legend class="label">옵션</legend>
            <p class="mb-3 text-xs text-mist">HOT/ICE, 사이즈처럼 고르는 항목이 있으면 추가하세요. 옵션이 있으면 재고는 옵션마다 따로 관리해요.</p>
            <div class="space-y-2">
                <template x-for="(o, i) in options" :key="i">
                    <div class="grid grid-cols-[1fr_110px_100px_auto] items-center gap-2">
                        <input type="hidden" :name="`options[${i}][id]`" :value="o.id ?? ''">
                        <input class="field !py-2.5" :name="`options[${i}][name]`" x-model="o.name" placeholder="옵션 이름" :aria-label="`옵션 ${i + 1} 이름`" required>
                        <input class="field num !py-2.5" type="number" min="0" step="100" :name="`options[${i}][extra_price]`" x-model="o.extra_price" placeholder="추가금" :aria-label="`옵션 ${i + 1} 추가금`">
                        <input class="field num !py-2.5" type="number" min="0" :name="`options[${i}][stock]`" x-model="o.stock" placeholder="재고" :aria-label="`옵션 ${i + 1} 재고 (비우면 무제한)`">
                        <button type="button" @click="options.splice(i, 1)" class="rounded-full px-2 py-2 text-sm font-semibold text-signal" :aria-label="`옵션 ${i + 1} 삭제`">삭제</button>
                    </div>
                </template>
            </div>
            <button type="button" @click="options.push({ name: '', extra_price: 0, stock: '' })" class="btn-ghost mt-3 !py-2 text-sm">옵션 추가</button>
            @error('options.*')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        </fieldset>
    </div>

    <div class="space-y-5">
        <div class="space-y-4 rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <div x-show="!options.length">
                <label for="stock" class="label">재고</label>
                <input id="stock" name="stock" type="number" min="0" class="field num" value="{{ old('stock', $p?->stock) }}" placeholder="비우면 무제한" :disabled="options.length > 0">
            </div>
            <div>
                <span class="label">재고 줄이는 시점</span>
                @php($deduct = old('stock_deduct_at', $p?->stock_deduct_at ?? 'order'))
                <label class="flex items-center gap-2 py-1 text-sm"><input type="radio" name="stock_deduct_at" value="order" @checked($deduct === 'order')> 주문할 때 <span class="text-mist">(권장 · 초과판매 없음)</span></label>
                <label class="flex items-center gap-2 py-1 text-sm"><input type="radio" name="stock_deduct_at" value="payment" @checked($deduct === 'payment')> 결제할 때</label>
            </div>
            <label class="flex items-center gap-2 text-sm font-semibold">
                <input type="checkbox" name="is_visible" value="1" @checked(old('is_visible', $p?->is_visible ?? true))> 팬에게 판매
            </label>
        </div>
        <button class="btn-ink w-full">{{ $p ? '저장' : '상품 등록' }}</button>
        <a href="{{ route('partner.products') }}" class="btn-ghost w-full">목록으로</a>
    </div>
</form>
@endsection
