@extends('layouts.backoffice')
@section('title', $store->name.' 주문보드')
@section('eyebrow', '실시간 · 4초마다 갱신')

@section('actions')
    <a href="{{ route('display', $store) }}" target="_blank" class="btn-ghost !py-2.5 text-sm">픽업 전광판 열기</a>
@endsection

@section('content')
{{-- 매장 주문보드 (원본 seller_order_board.php + ajax_seller_order_board_v2.php)
     - 앞으로·뒤로: 단계마다 되돌리기, 누른 직후 8초 동안 "실행 취소"
     - 주문이 많을 때: 먼저 들어온 순, 기다린 시간 표시(10분↑ 강조), 번호·상품 검색, 촘촘히 보기, 칸 안 스크롤 --}}
<div x-data="{
        orders: [], slots: [], flash: 0, error: '', busy: null, now: Date.now() / 1000,
        q: '', compact: false, undo: null, undoTimer: null,
        names: { pre: '공연 전', intermission: '인터미션', post: '공연 후' },
        cols: [
            { status: 2, title: '새 주문', next: 3, action: '만들기 시작' },
            { status: 3, title: '만드는 중', next: 4, action: '다 됐어요', back: '새 주문으로' },
            { status: 4, title: '픽업 대기', next: 5, action: '건네줬어요', back: '만드는 중으로' },
        ],
        labels: { 2: '새 주문', 3: '만드는 중', 4: '픽업 대기', 5: '건넴', 11: '취소' },
        init() {
            try { this.compact = localStorage.getItem('board_compact') === '1' } catch (e) {}
            this.$watch('compact', v => { try { localStorage.setItem('board_compact', v ? '1' : '0') } catch (e) {} });
            this.load(); setInterval(() => this.load(), 4000); setInterval(() => this.now = Date.now() / 1000, 15000);
        },
        async load() {
            try {
                const d = await api('{{ route('partner.board.data') }}');
                this.orders = d.orders; this.slots = d.slots; this.error = '';
                if (d.new_orders) { this.flash = d.new_orders; setTimeout(() => this.flash = 0, 4000) }
            } catch (e) { this.error = e.message }
        },
        async move(o, status, silent = false) {
            if (status === 11 && !confirm(o.pickup_code + ' 주문을 취소하고 환불할까요? (취소는 되돌릴 수 없어요)')) return;
            const from = o.status;
            this.busy = o.order_no; this.error = '';
            try {
                await api('{{ url('partner/orders') }}/' + o.order_no + '/status', 'PATCH', { status });
                // 앞으로 보낸 경우에만 '실행 취소' 를 띄운다 (취소·되돌리기는 제외)
                if (!silent && status !== 11 && status > from) this.offerUndo(o, from, status);
                else this.clearUndo();
                await this.load();
            } catch (e) { this.error = e.message }
            this.busy = null;
        },
        back(o) { return this.move(o, o.status - 1, true) },
        offerUndo(o, from, to) {
            clearTimeout(this.undoTimer);
            this.undo = { order: { ...o, status: to }, from, to };
            this.undoTimer = setTimeout(() => this.undo = null, 8000);
        },
        clearUndo() { clearTimeout(this.undoTimer); this.undo = null },
        async doUndo() {
            const u = this.undo; if (!u) return;
            this.clearUndo();
            await this.move(u.order, u.from, true);
        },
        waited(o) { return Math.max(0, Math.floor((this.now - o.ordered_ts) / 60)) },
        match(o) {
            const q = this.q.trim(); if (!q) return true;
            return String(o.pickup_code).includes(q) || o.items.some(i => (i.name + ' ' + (i.option || '')).includes(q));
        },
        of(status) { return this.orders.filter(o => o.status === status && this.match(o)) },
        count(status) { return this.orders.filter(o => o.status === status).length },
        async toggle(s) {
            try { await api('{{ url('partner/slots') }}/' + s.id + '/toggle', 'PATCH'); await this.load() } catch (e) { this.error = e.message }
        },
     }"
     @keydown.window.ctrl.z="if (undo && !['INPUT','TEXTAREA'].includes($event.target.tagName)) { $event.preventDefault(); doUndo() }">

    {{-- 시간대별 캐파: 매장 판단으로 조기 마감/재개 --}}
    <ul class="mb-4 flex flex-wrap gap-2">
        <template x-for="s in slots" :key="s.id">
            <li class="flex items-center gap-3 rounded-2xl bg-white py-2 pl-4 pr-2 ring-1 ring-ink/5">
                <div>
                    <p class="text-xs font-semibold" x-text="names[s.slot]"></p>
                    <p class="num text-sm font-bold"><span x-text="s.used"></span><span class="text-mist">/<span x-text="s.capacity"></span></span></p>
                </div>
                <button type="button" @click="toggle(s)" class="rounded-full px-3 py-1.5 text-xs font-bold"
                        :class="s.is_closed ? 'bg-signal text-white' : (s.open ? 'bg-stick/10 text-stick' : 'bg-ink/10 text-mist')"
                        x-text="s.is_closed ? '마감함 · 다시 열기' : (s.open ? '받는 중 · 마감하기' : '자동 마감')"></button>
            </li>
        </template>
        <li x-show="!slots.length" class="text-sm text-mist">예정된 공연이 없어 주문 시간대가 없어요.</li>
    </ul>

    {{-- 찾기 · 보기 --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <label class="min-w-56 flex-1 sm:max-w-xs">
            <span class="sr-only">주문 번호나 상품으로 찾기</span>
            <input type="search" x-model="q" placeholder="주문 번호·상품으로 찾기" class="field !py-2.5 text-sm">
        </label>
        <div class="flex rounded-full bg-white p-1 text-xs font-bold ring-1 ring-ink/10" role="group" aria-label="보기">
            <button type="button" @click="compact = false" :class="!compact ? 'bg-ink text-white' : 'text-mist'" class="rounded-full px-3 py-1.5" :aria-pressed="!compact">크게</button>
            <button type="button" @click="compact = true" :class="compact ? 'bg-ink text-white' : 'text-mist'" class="rounded-full px-3 py-1.5" :aria-pressed="compact">촘촘히</button>
        </div>
        <p class="text-xs text-mist">먼저 들어온 주문이 위 · 10분 넘게 기다린 주문은 빨간 테두리</p>
    </div>

    <div x-show="flash" x-cloak x-transition class="fixed left-1/2 top-6 z-30 -translate-x-1/2 rounded-full bg-ink px-6 py-3 font-bold text-pick shadow-xl" role="status">
        새 주문 <span x-text="flash"></span>건
    </div>
    <p x-show="error" x-cloak x-text="error" role="alert" class="mb-4 rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal"></p>

    <div class="grid gap-5 xl:grid-cols-3">
        <template x-for="col in cols" :key="col.status">
            <section class="flex min-h-[50vh] flex-col rounded-[26px] bg-paper-deep/60 p-3 xl:max-h-[calc(100dvh-16rem)]">
                <h2 class="flex items-center justify-between px-2 pb-3 pt-1 font-bold">
                    <span x-text="col.title"></span>
                    <span class="num rounded-full bg-white px-2.5 py-0.5 text-sm">
                        <span x-text="of(col.status).length"></span><span x-show="q && of(col.status).length !== count(col.status)" class="text-mist" x-text="'/' + count(col.status)"></span>
                    </span>
                </h2>
                <ul class="-mx-1 flex-1 overflow-y-auto px-1 pb-1" :class="compact ? 'space-y-1.5' : 'space-y-3'">
                    <template x-for="o in of(col.status)" :key="o.order_no">
                        <li class="rounded-2xl bg-white shadow-sm" :class="[compact ? 'p-3' : 'p-4', o.status === 4 ? 'ring-2 ring-pick' : (waited(o) >= 10 ? 'ring-2 ring-signal/60' : '')]">
                            <div class="flex items-start justify-between gap-2">
                                <p class="num font-black leading-none" :class="compact ? 'text-2xl' : 'text-4xl'" x-text="o.pickup_code"></p>
                                <div class="text-right text-xs text-mist">
                                    <p><span class="num" x-text="o.ordered_at"></span> · <span class="num font-bold" :class="waited(o) >= 10 && 'text-signal'" x-text="waited(o) + '분째'"></span></p>
                                    <p x-show="o.channel === 'pos'" class="font-semibold text-ink">현장판매</p>
                                </div>
                            </div>
                            <ul class="mt-2 space-y-0.5 text-sm">
                                <template x-for="(i, idx) in (compact ? o.items.slice(0, 2) : o.items)" :key="idx">
                                    <li class="flex justify-between gap-2">
                                        <span class="min-w-0 truncate"><b x-text="i.name"></b><span x-show="i.option" class="text-mist" x-text="' · ' + i.option"></span></span>
                                        <span class="shrink-0"><span class="num font-bold" x-text="'×' + i.qty"></span> <span class="text-xs text-mist" x-text="i.dine_option"></span></span>
                                    </li>
                                </template>
                                <li x-show="compact && o.items.length > 2" class="text-xs text-mist" x-text="'외 ' + (o.items.length - 2) + '개'"></li>
                            </ul>
                            <p x-show="o.seat && !compact" class="mt-2 text-xs text-mist" x-text="'좌석 ' + o.seat"></p>
                            <p x-show="o.id_check" class="mt-2 rounded-xl bg-signal px-3 py-1.5 text-xs font-bold text-white">주류 · 건네기 전에 신분증 확인</p>
                            <a x-show="o.cancel_request" :href="'{{ url('partner/orders') }}/' + o.order_no" class="mt-2 block rounded-xl bg-signal/10 px-3 py-1.5 text-xs font-bold text-signal">
                                구매자 취소 요청 · <span x-text="o.cancel_request"></span> → 답변하기
                            </a>
                            <div class="flex gap-2" :class="compact ? 'mt-2' : 'mt-4'">
                                <button type="button" x-show="col.back && o.can_revert" @click="back(o)" :disabled="busy === o.order_no"
                                        class="btn !px-3 text-sm text-mist ring-1 ring-ink/10 hover:bg-ink/5" :class="compact ? '!py-2' : '!py-2.5'"
                                        :title="col.back + ' 되돌리기'" :aria-label="o.pickup_code + ' ' + col.back + ' 되돌리기'">←<span x-show="!compact" class="ml-1" x-text="col.back"></span></button>
                                <button type="button" @click="move(o, col.next)" :disabled="busy === o.order_no"
                                        class="btn-ink flex-1 text-sm" :class="compact ? '!py-2' : '!py-2.5'" x-text="col.action"></button>
                                <button type="button" x-show="!o.cancel_disabled" @click="move(o, 11)" :disabled="busy === o.order_no"
                                        class="btn !px-3 text-sm text-signal ring-1 ring-signal/30 hover:bg-signal/5" :class="compact ? '!py-2' : '!py-2.5'">취소</button>
                            </div>
                            <a x-show="!compact" :href="'{{ url('partner/orders') }}/' + o.order_no" class="mt-2 inline-block text-xs font-semibold text-mist underline-offset-4 hover:underline">주문 상세</a>
                        </li>
                    </template>
                </ul>
                <p x-show="!of(col.status).length" class="px-2 py-8 text-center text-sm text-mist" x-text="q && count(col.status) ? '찾는 주문이 없어요' : '비어 있어요'"></p>
            </section>
        </template>
    </div>

    {{-- 방금 건네준 주문: 눌러서 픽업 대기로 되돌리기 --}}
    <section x-show="count(5)" class="mt-6">
        <h2 class="mb-2 text-sm font-bold text-mist">방금 건네준 주문 <span class="font-normal">· 잘못 눌렀으면 번호를 눌러 픽업 대기로 되돌려요 (10분 안)</span></h2>
        <ul class="flex flex-wrap gap-2">
            <template x-for="o in of(5)" :key="o.order_no">
                <li>
                    <button type="button" :disabled="!o.can_revert || busy === o.order_no"
                            @click="confirm(o.pickup_code + ' 주문을 픽업 대기로 되돌릴까요?') && back(o)"
                            class="num rounded-xl bg-white px-3 py-1.5 text-sm font-bold text-mist ring-1 ring-ink/5 enabled:hover:text-ink enabled:hover:ring-ink/20"
                            :title="o.can_revert ? '픽업 대기로 되돌리기' : '되돌릴 수 있는 시간이 지났어요'">
                        <span x-text="o.pickup_code"></span><span x-show="o.can_revert" class="ml-1 font-sans text-xs">↩</span>
                    </button>
                </li>
            </template>
        </ul>
    </section>

    {{-- 실행 취소 (8초) --}}
    <div x-show="undo" x-cloak x-transition.opacity class="fixed inset-x-0 bottom-6 z-40 flex justify-center px-4" role="status" aria-live="polite">
        <div class="flex items-center gap-4 rounded-full bg-ink py-2 pl-5 pr-2 text-sm text-paper shadow-2xl">
            <span><b class="num" x-text="undo?.order.pickup_code"></b> → <span x-text="labels[undo?.to]"></span></span>
            <button type="button" @click="doUndo()" class="rounded-full bg-pick px-4 py-2 font-bold text-ink">실행 취소</button>
        </div>
    </div>
</div>
@endsection
