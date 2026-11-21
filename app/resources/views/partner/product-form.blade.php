@extends('layouts.backoffice')
@section('title', $product ? '상품 수정' : '상품 등록')
@section('eyebrow', $store->name)

@php($p = $product)
@php($options = old('options', $p ? $p->options->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'extra_price' => $o->extra_price, 'stock' => $o->stock])->all() : []))

@section('content')
<form method="POST" action="{{ $p ? route('partner.products.update', $p) : route('partner.products.store') }}"
      enctype="multipart/form-data"
      x-data="{ options: @js(array_values($options)), preview: @js($p?->imageUrl()), removeImage: false }"
      class="grid max-w-4xl gap-6 lg:grid-cols-[1fr_320px]">
    @csrf
    @if ($p) @method('PUT') @endif

    <div class="space-y-5 rounded-3xl bg-white p-6 ring-1 ring-ink/5">
        @if ($p?->isBlocked())
            <p class="rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal">운영사가 판매를 중지한 상품이에요 · {{ $p->block_reason }}. 내용을 고친 뒤 운영사에 해제를 요청해 주세요.</p>
        @endif
        {{-- 카테고리: 이 매장 카테고리에서 고르거나, 새로 만들기 (관리는 상품 화면 위 '카테고리 관리') --}}
        <div x-data="{ adding: @js(filled(old('new_category'))) }">
            <label for="category_id" class="label">카테고리 <span class="font-normal text-mist">(손님 메뉴·현장판매에서 묶어 보여요)</span></label>
            <div class="flex gap-2" x-show="!adding">
                <select id="category_id" name="category_id" class="field" :disabled="adding">
                    <option value="">{{ \App\Models\ProductCategory::UNCATEGORIZED }} (카테고리 없음)</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}" @selected((string) old('category_id', $p?->category_id) === (string) $c->id)>{{ $c->name }}{{ $c->is_visible ? '' : ' (숨김)' }}</option>
                    @endforeach
                </select>
                <button type="button" @click="adding = true; $nextTick(() => $refs.newCat.focus())" class="btn-ghost shrink-0 !px-3 text-sm">+ 새 카테고리</button>
            </div>
            <div class="flex gap-2" x-show="adding" x-cloak>
                <input x-ref="newCat" name="new_category" maxlength="30" class="field" value="{{ old('new_category') }}" placeholder="새 카테고리 이름 (예: 디저트)" :disabled="!adding">
                <button type="button" @click="adding = false" class="btn-ghost shrink-0 !px-3 text-sm">목록에서 고르기</button>
            </div>
            @error('category_id')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            @error('new_category')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="name" class="label">상품명</label>
            <input id="name" name="name" class="field" value="{{ old('name', $p?->name) }}" placeholder="예: 아메리카노" required>
            @error('name')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        </div>
        {{-- 상품 이미지 --}}
        <div>
            <span class="label">상품 이미지 <span class="font-normal text-mist">(선택 · JPG·PNG·WEBP, 5MB 까지, 정사각형 추천)</span></span>
            <div class="flex items-center gap-4">
                <div class="grid size-24 shrink-0 place-items-center overflow-hidden rounded-2xl bg-paper ring-1 ring-ink/10">
                    <img x-show="preview && !removeImage" :src="preview" alt="상품 이미지 미리보기" class="size-full object-cover">
                    <span x-show="!preview || removeImage" class="text-xs text-mist">이미지 없음</span>
                </div>
                <div class="space-y-2 text-sm">
                    <input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp"
                           @change="const f = $event.target.files[0]; if (f) { preview = URL.createObjectURL(f); removeImage = false }"
                           class="block text-sm file:mr-3 file:rounded-full file:border-0 file:bg-ink file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white">
                    @if ($p?->image_path)
                        <label class="flex items-center gap-2 text-mist"><input type="checkbox" name="remove_image" value="1" x-model="removeImage"> 이미지 빼기</label>
                    @endif
                </div>
            </div>
            @error('image')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="description" class="label">설명 <span class="font-normal text-mist">(선택)</span></label>
            <textarea id="description" name="description" rows="2" class="field" placeholder="용량, 맛, 구성 등">{{ old('description', $p?->description) }}</textarea>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="origin" class="label">원산지 <span class="font-normal text-mist">(식품 표시)</span></label>
                <input id="origin" name="origin" class="field" value="{{ old('origin', $p?->origin) }}" placeholder="예: 원두 에티오피아산, 우유 국산">
            </div>
            <div>
                <label for="allergens" class="label">알레르기 유발 성분</label>
                <input id="allergens" name="allergens" class="field" value="{{ old('allergens', $p?->allergens) }}" placeholder="예: 우유, 밀, 대두">
            </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="price" class="label">기본 가격 (원)</label>
                <input id="price" name="price" type="number" min="0" step="100" class="field num" value="{{ old('price', $p?->price) }}" required>
                @error('price')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="mileage_points" class="label">적립 마일리지 (1개당, 원)</label>
                <input id="mileage_points" name="mileage_points" type="number" min="0" step="10" class="field num" value="{{ old('mileage_points', $p?->mileage_points ?? 0) }}">
                <p class="mt-1 text-xs text-mist">손님이 받아 가면 적립돼요. 우리 업체 부담이라 정산에서 빠지고, 판매가의 {{ \App\Models\Setting::get('mileage_product_max_rate') }}%까지 걸 수 있어요.</p>
                @error('mileage_points')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
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
                        <input class="field num !py-2.5" type="number" min="0" :name="`options[${i}][stock]`" x-model="o.stock" placeholder="재고" :aria-label="`옵션 ${i + 1} 재고 (비우면 무제한)`"
                               :disabled="!!o.id" :title="o.id ? '기존 옵션 재고는 생산·재고 화면에서 바꿔요' : ''">
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
            @if ($p)
                <div class="rounded-2xl bg-paper p-3 text-sm">
                    <p class="font-semibold">재고</p>
                    <p class="mt-0.5 text-mist">재고 수량은 <a href="{{ route('partner.inventory') }}" class="row-link">생산·재고</a>에서 생산·폐기·재고 맞춤으로 바꿔요. 바뀐 내역이 모두 기록돼요.</p>
                </div>
            @else
                <div x-show="!options.length">
                    <label for="stock" class="label">처음 재고</label>
                    <input id="stock" name="stock" type="number" min="0" class="field num" value="{{ old('stock') }}" placeholder="비우면 무제한" :disabled="options.length > 0">
                </div>
            @endif
            @php($liquorOk = $vendor->canSellLiquor())
            <label class="flex items-start gap-2 text-sm">
                {{-- 승인 전에는 끄는 것만 가능 (이미 주류인 상품은 체크를 풀 수 있게 남겨 둔다) --}}
                <input type="checkbox" name="is_alcohol" value="1" class="mt-1" @checked(old('is_alcohol', $p?->is_alcohol)) @disabled(! $liquorOk && ! $p?->is_alcohol)>
                <span><b>주류</b><span class="block text-xs text-mist">팬은 성인 확인 후 주문하고, 매장은 건넬 때 신분증을 확인해야 해요.</span>
                    @unless ($liquorOk)
                        <span class="mt-1 block rounded-xl bg-pick px-3 py-2 text-xs">
                            주류판매 자격: <b>{{ $vendor->liquorStatusLabel() }}</b> —
                            {{ match ($vendor->liquor_status) {
                                'pending' => '운영사가 서류를 확인하고 있어요. 승인되면 주류를 켤 수 있어요.',
                                'rejected' => '반려됐어요. 서류를 고쳐 다시 신청해 주세요.',
                                default => '주류는 영업형태(일반음식점·제과점 등)를 서류로 확인받은 업체만 팔 수 있어요.',
                            } }}
                            <a href="{{ route('partner.liquor') }}" class="row-link font-bold">주류판매 자격 신청 →</a>
                        </span>
                    @endunless
                </span>
            </label>
            @error('is_alcohol')<p class="text-sm text-signal">{{ $message }}</p>@enderror
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
        @unless ($p)
            <label class="flex items-start gap-2 rounded-3xl bg-white p-4 text-sm ring-1 ring-ink/5">
                <input type="checkbox" name="agree_prohibited" value="1" class="mt-1" @checked(old('agree_prohibited')) required>
                <span>의약품·담배·총포·청소년유해물·위조품 등 <b>판매 금지 품목이 아니에요.</b></span>
            </label>
            @error('agree_prohibited')<p class="text-sm text-signal">{{ $message }}</p>@enderror
        @endunless
        <button class="btn-ink w-full">{{ $p ? '저장' : '상품 등록' }}</button>
        <a href="{{ route('partner.products') }}" class="btn-ghost w-full">목록으로</a>
    </div>
</form>
@endsection
