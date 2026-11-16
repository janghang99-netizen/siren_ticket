@extends('layouts.backoffice')
@section('title', '좌석 막기 · '.$event->artist)
@section('eyebrow', '좌석티켓 · 판매 제외 · 보류')

@section('content')
<script>
    // 공연별 좌석 막기 — 구역 좌석을 고르고 판매 제외 / 보류 / 풀기
    window.eventSeats = (cfg) => ({
        sections: cfg.sections, sectionId: cfg.sectionId, data: null, chosen: [], loading: false,
        get section() { return this.sections.find(s => s.id === this.sectionId) },
        async load() {
            if (!this.sectionId) return;
            this.loading = true; this.chosen = []; this.data = null;
            const r = await fetch(cfg.url.replace(/\/0$/, '/' + this.sectionId), { headers: { Accept: 'application/json' } });
            this.data = await r.json(); this.loading = false;
            this.$nextTick(() => this.draw());
        },
        rows() {
            const d = this.data;
            return d.layout ? d.layout : Array.from({ length: d.rows }, (_, i) => ({ n: i + 1, label: String(i + 1), seats: Array.from({ length: d.per_row }, (_, j) => j + 1) }));
        },
        draw() {
            const esc = (s) => String(s).replace(/[&<>"']/g, ch => '&#' + ch.charCodeAt(0) + ';');
            const sold = new Set(this.data.sold), blocks = this.data.blocks || {};
            let h = '';
            for (const r of this.rows()) {
                h += `<div class="seat-row"><button type="button" class="seat-row-no" data-row="${r.n}" title="이 열 전체 고르기">${esc(r.label)}열</button><span class="seat-row-seats">`;
                for (const n of r.seats) {
                    const k = r.n + '-' + n, b = blocks[k], s = sold.has(k), c = this.chosen.includes(k);
                    const cls = s ? 'is-sold' : (b ? 'is-' + b.kind : '');
                    h += `<button type="button" data-seat="${k}" class="seat ${cls}${c ? ' is-chosen' : ''}" ${s ? 'disabled' : ''} title="${esc(r.label)}열 ${n}번${b && b.note ? ' · ' + esc(b.note) : ''}">${n}</button>`;
                }
                h += '</span></div>';
            }
            this.$refs.grid.innerHTML = `<div class="seat-rows${this.data.layout ? ' is-listed' : ''}">${h}</div>`;
        },
        click(e) {
            const rowBtn = e.target.closest('[data-row]');
            if (rowBtn) {   // 열 전체 (팔린 좌석 빼고)
                const row = this.rows().find(r => String(r.n) === rowBtn.dataset.row), sold = new Set(this.data.sold);
                const keys = row.seats.map(n => row.n + '-' + n).filter(k => !sold.has(k));
                const all = keys.every(k => this.chosen.includes(k));
                this.chosen = all ? this.chosen.filter(k => !keys.includes(k)) : [...new Set([...this.chosen, ...keys])];
                return this.draw();
            }
            const b = e.target.closest('[data-seat]'); if (!b || b.disabled) return;
            const k = b.dataset.seat, i = this.chosen.indexOf(k);
            i >= 0 ? this.chosen.splice(i, 1) : this.chosen.push(k);
            b.classList.toggle('is-chosen');
        },
    });
</script>

<div x-data="eventSeats(@js([
        'sections' => $sections->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'tier' => $s->tier, 'capacity' => $s->capacity, 'sold' => $s->sold, 'blocked' => $s->blocked, 'listed' => (bool) $s->layout])->values(),
        'sectionId' => (int) request('section', $sections->first()?->id),
        'url' => route('admin.events.seats.section', [$event->id, 0]),
     ]))" x-init="load()" class="grid gap-6 xl:grid-cols-[300px_minmax(0,1fr)]">

    <aside class="space-y-4">
        <div class="rounded-3xl bg-white p-5 text-sm ring-1 ring-ink/5">
            <p class="font-bold">{{ $event->artist }}</p>
            <p class="text-mist">{{ $event->name }} · {{ $event->starts_at->format('Y.m.d H:i') }}</p>
            <p class="mt-3 flex flex-wrap gap-1.5 text-xs">
                <span class="chip">판매 제외 <b class="num ml-1">{{ (int) ($counts['kill'] ?? 0) }}</b></span>
                <span class="chip">보류 <b class="num ml-1">{{ (int) ($counts['hold'] ?? 0) }}</b></span>
            </p>
            <p class="mt-3 text-xs text-mist">막은 좌석은 팬 화면에서 ‘판매됨’으로 보이고 팔리지 않아요. 보류석은 풀면 바로 판매돼요. 이미 팔린 좌석은 막을 수 없어요.</p>
            <a href="{{ route('admin.events.edit', $event->id) }}" class="btn-ghost mt-4 w-full !py-2 text-sm">공연으로 돌아가기</a>
        </div>
        @if ($sections->isEmpty())
            <p class="rounded-3xl bg-white p-5 text-sm text-mist ring-1 ring-ink/5">이 공연은 좌석을 직접 고르는 구역이 없어요 (기본 보울 공연은 좌석 자동 배정).</p>
        @else
            <ul class="max-h-[60vh] divide-y divide-ink/5 overflow-auto rounded-3xl bg-white text-sm ring-1 ring-ink/5">
                <template x-for="s in sections" :key="s.id">
                    <li><button type="button" class="flex w-full items-center justify-between gap-2 px-4 py-2.5 text-left hover:bg-paper" :class="s.id === sectionId && 'bg-paper font-bold'"
                                @click="sectionId = s.id; load()">
                        <span><span class="num" x-text="s.code"></span> <span class="text-xs font-normal text-mist" x-text="s.tier + (s.listed ? ' · 좌석 리스트' : '')"></span></span>
                        <span class="num text-xs text-mist" x-text="'판매 ' + won(s.sold) + ' · 막음 ' + won(s.blocked) + ' / ' + won(s.capacity)"></span>
                    </button></li>
                </template>
            </ul>
        @endif
    </aside>

    <section class="min-w-0 space-y-4" x-show="sectionId">
        <div class="rounded-3xl bg-white p-4 ring-1 ring-ink/5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-bold"><span class="num" x-text="section?.code"></span>구역 좌석</h2>
                <div class="flex flex-wrap gap-3 text-[11px] text-mist">
                    <span class="flex items-center gap-1"><span class="seat-key"></span>판매 가능</span>
                    <span class="flex items-center gap-1"><span class="seat-key is-chosen"></span>고른 좌석</span>
                    <span class="flex items-center gap-1"><span class="seat-key is-sold"></span>판매됨</span>
                    <span class="flex items-center gap-1"><span class="seat-key is-kill"></span>판매 제외</span>
                    <span class="flex items-center gap-1"><span class="seat-key is-hold"></span>보류</span>
                </div>
            </div>
            <p class="mt-1 text-xs text-mist">좌석을 눌러 고르세요. 열 이름을 누르면 그 열 전체를 골라요.</p>
            <p x-show="loading" class="mt-4 text-sm text-mist">불러오는 중…</p>
            <div x-ref="grid" @click="click($event)" class="seat-grid mt-3 max-h-[60vh] overflow-auto rounded-2xl bg-paper p-2"></div>
        </div>

        <form method="POST" action="{{ route('admin.events.seats.update', $event->id) }}" class="sticky bottom-4 flex flex-wrap items-center gap-2 rounded-3xl bg-ink p-3 text-sm text-white shadow-xl">
            @csrf
            <input type="hidden" name="section_id" :value="sectionId">
            <template x-for="k in chosen" :key="k"><input type="hidden" name="seats[]" :value="k"></template>
            <span class="px-2">고른 좌석 <b class="num" x-text="chosen.length"></b>석</span>
            <input name="note" maxlength="60" placeholder="메모 (예: 카메라석, 기획사 초대)" class="min-w-0 flex-1 rounded-full bg-white/10 px-4 py-2 text-white placeholder:text-white/50" aria-label="메모">
            <button name="action" value="kill" class="rounded-full bg-signal px-4 py-2 font-bold disabled:opacity-40" :disabled="!chosen.length">판매 제외</button>
            <button name="action" value="hold" class="rounded-full bg-pick px-4 py-2 font-bold text-ink disabled:opacity-40" :disabled="!chosen.length">보류</button>
            <button name="action" value="release" class="rounded-full bg-white px-4 py-2 font-bold text-ink disabled:opacity-40" :disabled="!chosen.length">풀기 (판매 가능)</button>
            <button type="button" class="px-2 text-white/60 underline" @click="chosen = []; draw()">선택 지우기</button>
        </form>
        @error('seats')<p class="text-sm text-signal">{{ $message }}</p>@enderror
    </section>
</div>

<style>
    .seat-rows { display: flex; flex-direction: column; width: max-content; min-width: 100% }
    .seat-rows.is-listed { align-items: center }
    .seat-row { display: flex; align-items: center; gap: 3px; margin-bottom: 3px }
    .seat-row-seats { display: flex; gap: 3px }
    .seat-row-no { width: 2.8rem; flex: none; padding-right: 4px; font-size: 10px; font-weight: 700; color: #8A84A3; text-align: right }
    .seat-row-no:hover { color: #17132B; text-decoration: underline }
    .seat, .seat-key { width: 24px; height: 24px; flex: none; border-radius: 6px 6px 4px 4px; background: #fff; box-shadow: inset 0 0 0 1px rgba(23,19,43,.18); font-size: 9px; font-weight: 600; color: #8A84A3 }
    .seat-key { display: inline-block; width: 14px; height: 14px; border-radius: 4px }
    .seat.is-sold, .seat-key.is-sold { background: rgba(23,19,43,.35); color: transparent; box-shadow: none; cursor: not-allowed }
    .seat.is-kill, .seat-key.is-kill { background: #F04A4A; color: #fff; box-shadow: none }
    .seat.is-hold, .seat-key.is-hold { background: #FFD43B; color: #17132B; box-shadow: none }
    .seat.is-chosen, .seat-key.is-chosen { outline: 3px solid #17132B; outline-offset: 1px }
</style>
@endsection
