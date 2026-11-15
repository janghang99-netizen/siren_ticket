@extends('layouts.backoffice')
@section('title', $store->name.' 현장판매')
@section('eyebrow', '매장 PC · 카드 단말기 · 번호표 인쇄')

@section('actions')
    <a href="{{ route('partner.board') }}" class="btn-ghost !py-2.5 text-sm">주문보드</a>
@endsection

@section('content')
{{-- 현장판매 (원본 seller_pos.php + ajax_seller_pos_order.php)
     결제가 끝나면 손님 번호표를 바로 인쇄한다 (숨은 iframe 으로 인쇄 화면을 열어 print).
     인쇄 확인 창 없이 바로 찍으려면 매장 PC 의 크롬을 --kiosk-printing 으로 실행 (docs/pos-printing.md) --}}
<div x-data="{
        menu: @js($menu),
        terminals: @js($terminals),
        recent: @js($recent),
        cat: null, cart: [], dine: '포장', method: 'pos_card', terminalId: @js($terminals->first()->id ?? null),
        approval: '', phone: '', received: '', busy: false, error: '', done: null,
        autoPrint: true, kitchenCopy: false, width: 80, settings: false,
        methods: { pos_card: '카드', pos_cash: '현금', pos_transfer: '계좌이체' },
        init() {
            try {
                const s = JSON.parse(localStorage.getItem('pos_print') || '{}');
                if (s.autoPrint !== undefined) this.autoPrint = s.autoPrint;
                if (s.kitchenCopy !== undefined) this.kitchenCopy = s.kitchenCopy;
                if (s.width) this.width = s.width;
            } catch (e) {}
            ['autoPrint', 'kitchenCopy', 'width'].forEach(k => this.$watch(k, () => {
                try { localStorage.setItem('pos_print', JSON.stringify({ autoPrint: this.autoPrint, kitchenCopy: this.kitchenCopy, width: Number(this.width) })) } catch (e) {}
            }));
            this.cat = '전체';
        },
        get cats() { return ['전체', ...new Set(this.menu.map(p => p.cat))] },
        get shown() { return this.cat === '전체' ? this.menu : this.menu.filter(p => p.cat === this.cat) },
        countIn(c) { return c === '전체' ? this.menu.length : this.menu.filter(p => p.cat === c).length },
        add(p, o = null) {
            if (p.soldOut || (o && o.soldOut)) return;
            const key = p.id + ':' + (o ? o.id : '');
            const line = this.cart.find(l => l.key === key);
            if (line) line.qty++;
            else this.cart.push({ key, product_id: p.id, option_id: o ? o.id : null, name: p.name + (o ? ' · ' + o.name : ''), unit: p.price + (o ? o.extra : 0), qty: 1, alcohol: p.alcohol });
        },
        dec(l) { l.qty--; if (!l.qty) this.cart = this.cart.filter(x => x !== l) },
        get total() { return this.cart.reduce((a, l) => a + l.unit * l.qty, 0) },
        get count() { return this.cart.reduce((a, l) => a + l.qty, 0) },
        get change() { const r = Number(String(this.received).replace(/\D/g, '')) || 0; return r - this.total },
        get hasAlcohol() { return this.cart.some(l => l.alcohol) },
        get canPay() {
            if (!this.cart.length || this.busy) return false;
            if (this.method === 'pos_card') return !!this.terminalId && this.approval.trim().length > 0;
            if (this.method === 'pos_cash' && this.received !== '') return this.change >= 0;
            return true;
        },
        clear() { this.cart = []; this.approval = ''; this.phone = ''; this.received = ''; this.error = '' },
        async pay() {
            if (!this.canPay) return;
            this.busy = true; this.error = '';
            try {
                const res = await api('{{ route('partner.pos') }}', 'POST', {
                    pay_method: this.method,
                    items: this.cart.map(l => ({ product_id: l.product_id, option_id: l.option_id, qty: l.qty, dine_option: this.dine })),
                    terminal_id: this.method === 'pos_card' ? this.terminalId : null,
                    card_approval_no: this.method === 'pos_card' ? this.approval.trim() : null,
                    stamp_phone: this.phone.trim() || null,
                });
                this.done = { ...res, change: this.method === 'pos_cash' && this.received !== '' ? this.change : null, method: this.methods[this.method] };
                this.recent.unshift({ order_no: res.order_no, pickup_code: res.pickup_code, total: res.total, at: new Date().toTimeString().slice(0, 5), items: this.count, ticket_url: res.ticket_url });
                this.recent = this.recent.slice(0, 20);
                if (this.autoPrint) this.print(res.ticket_url, 'customer');
                if (this.kitchenCopy) setTimeout(() => this.print(res.ticket_url, 'kitchen'), 1200);
                this.clear();
            } catch (e) { this.error = e.message }
            this.busy = false;
        },
        // 숨은 iframe 에 인쇄 화면을 열고 print — 팝업 차단에 걸리지 않는다
        print(url, kind = 'customer', reprint = false) {
            const f = document.createElement('iframe');
            f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
            f.src = url + '?kind=' + kind + '&width=' + this.width + (reprint ? '&reprint=1' : '');
            f.onload = () => {
                try { f.contentWindow.focus(); f.contentWindow.print() } catch (e) { window.open(f.src + '&auto=1', '_blank') }
                setTimeout(() => f.remove(), 60000);
            };
            document.body.appendChild(f);
        },
        won(n) { return Number(n).toLocaleString('ko-KR') },
     }"
     @keydown.window.escape="done ? (done = null) : clear()"
     class="grid gap-5 xl:grid-cols-[1fr_380px]">

    {{-- 상품 --}}
    <section>
        <nav x-show="menu.length" class="mb-3 flex flex-wrap gap-2" aria-label="카테고리">
            <template x-for="c in cats" :key="c">
                <button type="button" @click="cat = c" :aria-pressed="cat === c"
                        class="rounded-full px-4 py-2 text-sm font-bold ring-1" :class="cat === c ? 'bg-ink text-white ring-ink' : 'bg-white ring-ink/10'">
                    <span x-text="c"></span> <span class="num text-xs opacity-60" x-text="countIn(c)"></span></button>
            </template>
        </nav>
        <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 2xl:grid-cols-4">
            <template x-for="p in shown" :key="p.id">
                <li class="rounded-3xl bg-white p-3 ring-1 ring-ink/5" :class="p.soldOut && 'opacity-40'">
                    <button type="button" @click="!p.options.length && add(p)" :disabled="p.soldOut || p.options.length > 0"
                            class="flex w-full items-center gap-3 text-left" :aria-label="p.name + ' 담기'">
                        <img x-show="p.img" :src="p.img" alt="" class="size-14 shrink-0 rounded-2xl object-cover">
                        <span class="min-w-0">
                            <span class="block truncate font-bold" x-text="p.name"></span>
                            <span class="num block text-sm text-mist" x-text="won(p.price) + '원'"></span>
                            <span x-show="p.soldOut" class="text-xs font-bold text-signal">품절</span>
                            <span x-show="p.alcohol" class="text-xs font-bold text-signal">주류 · 신분증</span>
                        </span>
                    </button>
                    <div x-show="p.options.length" class="mt-2 flex flex-wrap gap-1.5">
                        <template x-for="o in p.options" :key="o.id">
                            <button type="button" @click="add(p, o)" :disabled="p.soldOut || o.soldOut"
                                    class="rounded-full bg-paper px-3 py-1.5 text-sm font-semibold ring-1 ring-ink/5 hover:ring-ink/30 disabled:line-through disabled:opacity-40">
                                <span x-text="o.name"></span><span x-show="o.extra" class="num ml-1 text-xs text-mist" x-text="'+' + won(o.extra)"></span>
                            </button>
                        </template>
                    </div>
                </li>
            </template>
        </ul>
        <p x-show="!menu.length" class="rounded-3xl bg-white p-8 text-center text-sm text-mist">판매 중인 상품이 없어요. 상품 화면에서 등록해 주세요.</p>

        {{-- 오늘 현장판매 · 재인쇄 --}}
        <h2 class="mb-2 mt-8 text-sm font-bold text-mist">오늘 현장판매 <span class="font-normal">· 번호표를 다시 찍을 수 있어요</span></h2>
        <ul class="flex flex-wrap gap-2">
            <template x-for="r in recent" :key="r.order_no">
                <li class="flex items-center gap-2 rounded-2xl bg-white py-1.5 pl-3 pr-1.5 text-sm ring-1 ring-ink/5">
                    <b class="num" x-text="r.pickup_code"></b>
                    <span class="num text-xs text-mist" x-text="r.at + ' · ' + won(r.total) + '원'"></span>
                    <button type="button" @click="print(r.ticket_url, 'customer', true)" class="rounded-full bg-paper px-2.5 py-1 text-xs font-semibold hover:bg-ink hover:text-white">번호표</button>
                    <button type="button" @click="print(r.ticket_url, 'kitchen', true)" class="rounded-full bg-paper px-2.5 py-1 text-xs font-semibold hover:bg-ink hover:text-white">주방</button>
                </li>
            </template>
            <li x-show="!recent.length" class="text-sm text-mist">아직 없어요.</li>
        </ul>
    </section>

    {{-- 계산 --}}
    <aside class="rounded-[28px] bg-white p-5 ring-1 ring-ink/5 xl:sticky xl:top-6 xl:self-start">
        <div class="flex items-center justify-between">
            <h2 class="font-bold">주문 <span class="num text-mist" x-text="count ? count + '개' : ''"></span></h2>
            <button type="button" x-show="cart.length" @click="clear()" class="text-xs font-semibold text-mist hover:text-signal">비우기 (Esc)</button>
        </div>
        <ul class="mt-3 max-h-64 space-y-2 overflow-auto text-sm">
            <template x-for="l in cart" :key="l.key">
                <li class="flex items-center justify-between gap-2">
                    <span class="min-w-0 truncate" x-text="l.name"></span>
                    <span class="flex shrink-0 items-center gap-2">
                        <button type="button" @click="dec(l)" class="size-8 rounded-full bg-paper font-bold" :aria-label="l.name + ' 하나 빼기'">−</button>
                        <span class="num w-5 text-center font-bold" x-text="l.qty"></span>
                        <button type="button" @click="l.qty++" class="size-8 rounded-full bg-paper font-bold" :aria-label="l.name + ' 하나 더'">+</button>
                        <span class="num w-20 text-right" x-text="won(l.unit * l.qty)"></span>
                    </span>
                </li>
            </template>
            <li x-show="!cart.length" class="py-6 text-center text-mist">왼쪽에서 상품을 눌러 담아요.</li>
        </ul>

        <div class="mt-4 space-y-3 border-t border-ink/5 pt-4 text-sm">
            <div class="flex rounded-full bg-paper p-1 font-semibold" role="radiogroup" aria-label="매장 또는 포장">
                <template x-for="d in ['포장', '매장']">
                    <button type="button" role="radio" :aria-checked="dine === d" @click="dine = d" class="flex-1 rounded-full py-2" :class="dine === d ? 'bg-ink text-white' : 'text-mist'" x-text="d"></button>
                </template>
            </div>
            <div class="grid grid-cols-3 gap-1.5" role="radiogroup" aria-label="결제수단">
                <template x-for="(label, key) in methods" :key="key">
                    <button type="button" role="radio" :aria-checked="method === key" @click="method = key"
                            class="rounded-2xl py-2.5 font-bold ring-1" :class="method === key ? 'bg-ink text-white ring-ink' : 'bg-white ring-ink/10'" x-text="label"></button>
                </template>
            </div>

            {{-- 카드: 단말기 결제 후 승인번호 입력 (단말기 매출과 대사) --}}
            <div x-show="method === 'pos_card'" class="space-y-2">
                <select x-model.number="terminalId" class="field !py-2.5" aria-label="카드 단말기" :disabled="!terminals.length">
                    <template x-for="t in terminals" :key="t.id"><option :value="t.id" x-text="(t.label || t.tid) + ' · ' + t.provider"></option></template>
                </select>
                <p x-show="!terminals.length" class="text-xs font-semibold text-signal">이 매장에 등록된 카드 단말기가 없어요. <a href="{{ route('partner.terminals') }}#store-{{ $store->id }}" class="underline underline-offset-2">단말기 등록하기 →</a></p>
                <input x-model="approval" class="field num !py-2.5" placeholder="단말기 승인번호" maxlength="30" aria-label="카드 승인번호" inputmode="numeric">
            </div>
            {{-- 현금: 받은 돈 → 거스름돈 --}}
            <div x-show="method === 'pos_cash'" class="flex items-center gap-2">
                <input x-model="received" class="field num !py-2.5" placeholder="받은 돈 (선택)" inputmode="numeric" aria-label="받은 돈">
                <p x-show="received !== ''" class="shrink-0 font-bold" :class="change < 0 ? 'text-signal' : ''"
                   x-text="change < 0 ? won(-change) + '원 모자라요' : '거스름 ' + won(change) + '원'"></p>
            </div>
            <input x-model="phone" class="field num !py-2.5" placeholder="손님 휴대폰 (선택 · 스탬프·적립)" inputmode="tel" aria-label="손님 휴대폰 번호">
            <p x-show="hasAlcohol" class="rounded-xl bg-signal px-3 py-2 text-xs font-bold text-white">주류가 있어요 · 신분증을 확인해 주세요</p>
            <p x-show="error" x-text="error" role="alert" class="rounded-xl bg-signal/10 px-3 py-2 font-semibold text-signal"></p>

            <button type="button" @click="pay()" :disabled="!canPay" class="btn-ink w-full !py-4 text-base">
                <span class="num" x-text="won(total) + '원'"></span>
                <span x-text="busy ? '처리 중…' : (autoPrint ? '결제 완료 · 번호표 인쇄' : '결제 완료')"></span>
            </button>

            <button type="button" @click="settings = !settings" class="text-xs font-semibold text-mist" :aria-expanded="settings">인쇄 설정 ▾</button>
            <div x-show="settings" class="space-y-2 rounded-2xl bg-paper p-3 text-xs">
                <label class="flex items-center gap-2"><input type="checkbox" x-model="autoPrint"> 결제하면 손님 번호표 바로 인쇄</label>
                <label class="flex items-center gap-2"><input type="checkbox" x-model="kitchenCopy"> 주방 주문서도 함께 인쇄</label>
                <label class="flex items-center gap-2">용지 폭
                    <select x-model.number="width" class="field !w-auto !py-1 text-xs"><option value="80">80mm</option><option value="58">58mm</option></select></label>
                <p class="text-mist">확인 창 없이 바로 찍으려면 매장 PC 크롬을 <code>--kiosk-printing</code> 으로 실행하세요.</p>
            </div>
        </div>
    </aside>

    {{-- 결제 완료: 번호를 크게 --}}
    <div x-show="done" x-cloak x-transition.opacity class="fixed inset-0 z-50 grid place-items-center bg-ink/60 p-4" @click.self="done = null" role="dialog" aria-modal="true" aria-label="결제 완료">
        <div class="w-full max-w-sm rounded-[32px] bg-white p-8 text-center shadow-2xl">
            <p class="text-sm font-semibold text-mist">픽업 번호</p>
            <p class="num mt-1 text-8xl font-black leading-none" x-text="done?.pickup_code"></p>
            <p class="num mt-3 text-sm text-mist" x-text="done ? won(done.total) + '원 · ' + done.method : ''"></p>
            <p x-show="done && done.change !== null" class="num mt-1 text-lg font-bold" x-text="'거스름돈 ' + won(done?.change || 0) + '원'"></p>
            <div class="mt-6 grid grid-cols-2 gap-2">
                <button type="button" @click="print(done.ticket_url, 'customer', true)" class="btn !py-3 text-sm ring-1 ring-ink/15">번호표 다시 인쇄</button>
                <button type="button" @click="print(done.ticket_url, 'kitchen')" class="btn !py-3 text-sm ring-1 ring-ink/15">주방 주문서</button>
            </div>
            <button type="button" @click="done = null" class="btn-ink mt-2 w-full !py-3">다음 손님 (Esc)</button>
        </div>
    </div>
</div>
@endsection
