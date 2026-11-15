@extends('layouts.backoffice')
@section('title', $store->name.' 주문보드')
@section('eyebrow', '실시간 · 4초마다 갱신')

@section('actions')
    <a href="{{ route('display', $store) }}" target="_blank" class="btn-ghost !py-2.5 text-sm">픽업 전광판 열기</a>
@endsection

@section('content')
{{-- 매장 주문보드 (원본 seller_order_board.php + ajax_seller_order_board_v2.php) --}}
<div x-data="{
        orders: [], slots: [], flash: 0, error: '', busy: null,
        names: { pre: '공연 전', intermission: '인터미션', post: '공연 후' },
        cols: [
            { status: 2, title: '새 주문', next: 3, action: '만들기 시작' },
            { status: 3, title: '만드는 중', next: 4, action: '다 됐어요' },
            { status: 4, title: '픽업 대기', next: 5, action: '건네줬어요' },
        ],
        async load() {
            try {
                const d = await api('{{ route('partner.board.data') }}');
                this.orders = d.orders; this.slots = d.slots; this.error = '';
                if (d.new_orders) { this.flash = d.new_orders; setTimeout(() => this.flash = 0, 4000) }
            } catch (e) { this.error = e.message }
        },
        async move(o, status) {
            if (status === 11 && !confirm(o.pickup_code + ' 주문을 취소하고 환불할까요?')) return;
            this.busy = o.order_no; this.error = '';
            try {
                await api('{{ url('partner/orders') }}/' + o.order_no + '/status', 'PATCH', { status });
                await this.load();
            } catch (e) { this.error = e.message }
            this.busy = null;
        },
        async toggle(s) {
            try { await api('{{ url('partner/slots') }}/' + s.id + '/toggle', 'PATCH'); await this.load() } catch (e) { this.error = e.message }
        },
        of(status) { return this.orders.filter(o => o.status === status) },
     }"
     x-init="load(); setInterval(() => load(), 4000)">

    {{-- 시간대별 캐파: 매장 판단으로 조기 마감/재개 --}}
    <ul class="mb-5 flex flex-wrap gap-2">
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

    <div x-show="flash" x-cloak x-transition class="fixed left-1/2 top-6 z-30 -translate-x-1/2 rounded-full bg-ink px-6 py-3 font-bold text-pick shadow-xl" role="status">
        새 주문 <span x-text="flash"></span>건
    </div>
    <p x-show="error" x-cloak x-text="error" role="alert" class="mb-4 rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal"></p>

    <div class="grid gap-5 xl:grid-cols-3">
        <template x-for="col in cols" :key="col.status">
            <section class="min-h-[50vh] rounded-[26px] bg-paper-deep/60 p-3">
                <h2 class="flex items-center justify-between px-2 pb-3 pt-1 font-bold">
                    <span x-text="col.title"></span>
                    <span class="num rounded-full bg-white px-2.5 py-0.5 text-sm" x-text="of(col.status).length"></span>
                </h2>
                <ul class="space-y-3">
                    <template x-for="o in of(col.status)" :key="o.order_no">
                        <li class="rounded-2xl bg-white p-4 shadow-sm" :class="o.status === 4 && 'ring-2 ring-pick'">
                            <div class="flex items-start justify-between">
                                <p class="num text-4xl font-black leading-none" x-text="o.pickup_code"></p>
                                <div class="text-right text-xs text-mist">
                                    <p class="num" x-text="o.ordered_at"></p>
                                    <p x-show="o.channel === 'pos'" class="font-semibold text-ink">현장판매</p>
                                </div>
                            </div>
                            <ul class="mt-3 space-y-1 text-sm">
                                <template x-for="(i, idx) in o.items" :key="idx">
                                    <li class="flex justify-between gap-2">
                                        <span><b x-text="i.name"></b><span x-show="i.option" class="text-mist" x-text="' · ' + i.option"></span></span>
                                        <span class="shrink-0"><span class="num font-bold" x-text="'×' + i.qty"></span> <span class="text-xs text-mist" x-text="i.dine_option"></span></span>
                                    </li>
                                </template>
                            </ul>
                            <p x-show="o.seat" class="mt-2 text-xs text-mist" x-text="'좌석 ' + o.seat"></p>
                            <div class="mt-4 flex gap-2">
                                <button type="button" @click="move(o, col.next)" :disabled="busy === o.order_no"
                                        class="btn-ink flex-1 !py-2.5 text-sm" x-text="col.action"></button>
                                <button type="button" x-show="!o.cancel_disabled" @click="move(o, 11)" :disabled="busy === o.order_no"
                                        class="btn !py-2.5 text-sm text-signal ring-1 ring-signal/30 hover:bg-signal/5">취소</button>
                            </div>
                        </li>
                    </template>
                </ul>
                <p x-show="!of(col.status).length" class="px-2 py-8 text-center text-sm text-mist">비어 있어요</p>
            </section>
        </template>
    </div>

    <section x-show="of(5).length" class="mt-6">
        <h2 class="mb-2 text-sm font-bold text-mist">방금 건네준 주문</h2>
        <ul class="flex flex-wrap gap-2">
            <template x-for="o in of(5)" :key="o.order_no">
                <li class="num rounded-xl bg-white px-3 py-1.5 text-sm font-bold text-mist" x-text="o.pickup_code"></li>
            </template>
        </ul>
    </section>
</div>
@endsection
