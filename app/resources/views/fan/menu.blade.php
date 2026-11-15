@extends('layouts.fan')
@section('title', $store->name)
@section('tab', 'home')
@section('theme', $event->theme_color)

@php($slotNames = ['pre' => '공연 전', 'intermission' => '인터미션', 'post' => '공연 후'])
@php($menu = $store->products->map(fn ($p) => [
    'id' => $p->id, 'name' => $p->name, 'price' => $p->price, 'alcohol' => $p->is_alcohol,
    'desc' => $p->description, 'origin' => $p->origin, 'allergens' => $p->allergens,
    'soldOut' => $p->options->isEmpty() ? ($p->stock !== null && $p->stock <= 0) : $p->options->every(fn ($o) => $o->stock !== null && $o->stock <= 0),
    'options' => $p->options->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'extra' => $o->extra_price, 'soldOut' => $o->stock !== null && $o->stock <= 0])->values(),
])->values())
@php($slotData = $slots->map(fn ($s) => ['id' => $s->id, 'name' => $slotNames[$s->slot], 'left' => $s->remaining(), 'open' => $s->isOpen()])->values())

@section('header-action')
    <a href="{{ route('stores.index', $event) }}" class="text-sm font-semibold text-stick">매장 목록</a>
@endsection

@section('content')
<div x-data="{
        menu: @js($menu),
        slots: @js($slotData),
        slotId: @js(optional($slots->first(fn ($s) => $s->isOpen()))->id),
        dine: '포장',
        cart: [],
        busy: false,
        error: '',
        agree: false,
        adult: false,
        add(p, optionId = null) {
            const o = p.options.find(o => o.id === optionId);
            const key = p.id + ':' + (optionId ?? '');
            const line = this.cart.find(l => l.key === key);
            if (line) line.qty++;
            else this.cart.push({ key, product_id: p.id, option_id: optionId, alcohol: p.alcohol, name: p.name + (o ? ' · ' + o.name : ''), unit: p.price + (o ? o.extra : 0), qty: 1 });
        },
        get hasAlcohol() { return this.cart.some(l => l.alcohol) },
        get ready() { return this.agree && (!this.hasAlcohol || this.adult) },
        dec(line) { line.qty--; if (!line.qty) this.cart = this.cart.filter(l => l !== line) },
        get count() { return this.cart.reduce((a, l) => a + l.qty, 0) },
        get total() { return this.cart.reduce((a, l) => a + l.qty * l.unit, 0) },
        async order() {
            this.busy = true; this.error = '';
            try {
                const res = await api('{{ route('stores.order', [$event, $store]) }}', 'POST', {
                    slot_id: this.slotId,
                    agree_terms: this.agree,
                    adult_confirmed: this.adult,
                    items: this.cart.map(l => ({ product_id: l.product_id, option_id: l.option_id, qty: l.qty, dine_option: this.dine })),
                });
                location.href = res.pay_url;
            } catch (e) { this.error = e.message; this.busy = false }
        },
     }">

    <p class="eyebrow mt-2">{{ $event->venue }}</p>
    <h1 class="mt-2 text-2xl font-black">{{ $store->name }}</h1>
    @if ($ticket)
        <p class="mt-1 text-sm text-mist"><b class="text-ink">{{ $ticket->seatLabel() }}</b> 티켓으로 주문해요</p>
    @endif

    {{-- 주문 시간대: 매장 처리량만큼만 받는다 (카운트·시간 중 먼저 오는 쪽으로 마감) --}}
    <fieldset class="mt-6">
        <legend class="label">언제 받으세요?</legend>
        <div class="grid grid-cols-3 gap-2">
            <template x-for="s in slots" :key="s.id">
                <button type="button" @click="s.open && (slotId = s.id)" :disabled="!s.open" :aria-pressed="slotId === s.id"
                        class="rounded-2xl px-3 py-3 text-left ring-1 transition disabled:opacity-40"
                        :class="slotId === s.id ? 'bg-ink text-paper ring-ink' : 'bg-white ring-ink/10'">
                    <span class="block text-sm font-bold" x-text="s.name"></span>
                    <span class="num mt-0.5 block text-xs" :class="slotId === s.id ? 'text-paper/70' : 'text-mist'"
                          x-text="s.open ? won(s.left) + '건 남음' : '마감'"></span>
                </button>
            </template>
        </div>
        <p x-show="!slots.some(s => s.open)" class="mt-2 text-sm font-semibold text-signal">오늘 이 매장의 미리 주문이 모두 마감됐어요.</p>
    </fieldset>

    <ul class="mt-8 divide-y divide-ink/5 overflow-hidden rounded-3xl bg-white ring-1 ring-ink/5">
        <template x-for="p in menu" :key="p.id">
            <li class="px-5 py-4" :class="p.soldOut && 'opacity-50'">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-bold"><span x-text="p.name"></span>
                            <span x-show="p.alcohol" class="chip ml-1 bg-signal/10 align-middle text-[10px] text-signal">19세 이상</span></p>
                        <p x-show="p.desc" class="mt-0.5 text-xs text-mist" x-text="p.desc"></p>
                        <p x-show="p.origin || p.allergens" class="mt-0.5 text-[11px] text-mist"
                           x-text="[p.origin ? '원산지 ' + p.origin : '', p.allergens ? '알레르기 ' + p.allergens : ''].filter(Boolean).join(' · ')"></p>
                        <p x-show="!p.soldOut" class="num mt-0.5 text-sm" x-text="won(p.price) + '원'"></p>
                        <p x-show="p.soldOut" class="mt-0.5 text-sm font-semibold text-signal">품절</p>
                    </div>
                    <button type="button" x-show="!p.options.length" @click="add(p)" :disabled="p.soldOut"
                            class="btn-ghost !px-4 !py-2 text-sm" :aria-label="p.name + ' 담기'">담기</button>
                </div>
                <div x-show="p.options.length" class="mt-3 flex flex-wrap gap-2">
                    <template x-for="o in p.options" :key="o.id">
                        <button type="button" @click="add(p, o.id)" :disabled="o.soldOut"
                                class="rounded-full bg-paper px-3 py-1.5 text-sm font-semibold ring-1 ring-ink/5 transition hover:ring-stick/40 disabled:line-through disabled:opacity-40">
                            <span x-text="o.name"></span><span class="num ml-1 text-xs text-mist" x-show="o.extra" x-text="'+' + won(o.extra)"></span>
                        </button>
                    </template>
                </div>
            </li>
        </template>
    </ul>

    {{-- 장바구니가 메뉴 마지막 줄을 가리지 않게 여백 --}}
    <div x-show="count" class="h-56" aria-hidden="true"></div>

    {{-- 장바구니 --}}
    <div x-show="count" x-cloak x-transition.opacity class="fixed inset-x-0 bottom-[78px] z-30 mx-auto max-w-md px-4">
        <div class="rounded-[26px] bg-white p-4 shadow-2xl shadow-ink/20 ring-1 ring-ink/10">
            <ul class="max-h-40 space-y-2 overflow-auto text-sm">
                <template x-for="l in cart" :key="l.key">
                    <li class="flex items-center justify-between gap-2">
                        <span class="min-w-0 truncate" x-text="l.name"></span>
                        <span class="flex shrink-0 items-center gap-2">
                            <button type="button" @click="dec(l)" class="size-7 rounded-full bg-paper font-bold" :aria-label="l.name + ' 하나 빼기'">−</button>
                            <span class="num w-4 text-center font-bold" x-text="l.qty"></span>
                            <button type="button" @click="l.qty++" class="size-7 rounded-full bg-paper font-bold" :aria-label="l.name + ' 하나 더'">+</button>
                        </span>
                    </li>
                </template>
            </ul>
            <div class="mt-3 flex items-center gap-2 border-t border-ink/5 pt-3">
                <div class="flex rounded-full bg-paper p-1 text-xs font-semibold" role="radiogroup" aria-label="매장 또는 포장">
                    <template x-for="d in ['포장', '매장']">
                        <button type="button" role="radio" :aria-checked="dine === d" @click="dine = d"
                                class="rounded-full px-3 py-1.5" :class="dine === d ? 'bg-ink text-paper' : 'text-mist'" x-text="d"></button>
                    </template>
                </div>
                <button type="button" @click="order()" :disabled="busy || !slotId || !ready" class="btn-ink ml-auto !py-3 text-sm">
                    <span class="num" x-text="won(total) + '원'"></span>
                    <span x-text="busy ? '주문 중…' : '주문하기'"></span>
                </button>
            </div>
            <div class="mt-3 space-y-1.5 border-t border-ink/5 pt-3 text-xs">
                <label x-show="hasAlcohol" class="flex items-start gap-2 font-semibold text-signal">
                    <input type="checkbox" x-model="adult" class="mt-0.5">
                    <span>만 19세 이상이에요. 받을 때 신분증을 보여 줄게요. (주류 필수)</span>
                </label>
                <label class="flex items-start gap-2">
                    <input type="checkbox" x-model="agree" class="mt-0.5">
                    <span>주문 내용과 <a href="{{ route('policy', 'terms') }}" target="_blank" class="row-link">이용약관</a>, 판매자에게 주문 정보 제공에 동의해요 (필수)</span>
                </label>
            </div>
            <p x-show="error" x-text="error" role="alert" class="mt-2 text-sm font-semibold text-signal"></p>
        </div>
    </div>

    {{-- A2 판매자 신원정보 고지 (전자상거래법 §20) --}}
    @if ($store->vendor)
        <section class="mt-8 rounded-3xl bg-white/60 p-4 text-[11px] leading-relaxed text-mist ring-1 ring-ink/5" aria-label="판매자 정보">
            <p class="mb-1 font-bold text-ink/70">판매자 정보</p>
            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
                @foreach ($store->vendor->disclosure() as $k => $v)
                    <dt>{{ $k }}</dt><dd class="text-ink/70">{{ $v }}</dd>
                @endforeach
            </dl>
            <p class="mt-2">이 매장의 상품은 위 판매자가 판매하며, SIREN은 통신판매중개자로서 거래 당사자가 아닙니다.</p>
        </section>
    @endif
</div>
@endsection
