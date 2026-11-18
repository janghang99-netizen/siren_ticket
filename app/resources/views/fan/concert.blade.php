@extends('layouts.ticket')
@section('title', ($event->production?->title ?? $event->name).' 좌석 선택')
@section('genre', $event->production?->genre ?? '')

@php
    $days = ['일', '월', '화', '수', '목', '금', '토'];
    $palette = ['VIP' => '#8B5CF6', 'R' => '#3B82F6', 'S' => '#10B981', 'A' => '#F59E0B'];
    $tierColors = $event->sections->pluck('tier')->unique()->values()
        ->mapWithKeys(fn ($t, $i) => [$t => $palette[$t] ?? ['#EC4899', '#06B6D4', '#84CC16', '#F97316'][$i % 4]])->all();
    $sections = $event->sections->map(fn ($s) => [
        'id' => $s->id, 'code' => $s->code, 'tier' => $s->tier, 'name' => $s->name,
        'price' => $s->price, 'left' => $s->remaining(), 'grid' => $s->hasSeatGrid(),
        'color' => $s->color ?: ($tierColors[$s->tier] ?? '#8B5CF6'),
    ])->values();
    $tiers = $event->sections->groupBy('tier')->map(fn ($g, $t) => [
        'name' => $t, 'price' => (int) $g->min('price'), 'left' => (int) $g->sum(fn ($s) => $s->remaining()), 'color' => $tierColors[$t] ?? '#8B5CF6',
    ])->sortByDesc('price')->values();
    $hasMap = (bool) $event->seat_map_id;
    $p = $event->production;
    $when = fn ($e) => $e->starts_at->format('y.m.d').'('.$days[$e->starts_at->dayOfWeek].') '.$e->starts_at->format('H:i');
@endphp

@section('content')
<script>
    // 구역 좌석 격자 HTML (수천 석도 빠르게 — 버튼마다 Alpine 바인딩을 걸지 않고 한 번에 그린다)
    // 좌석 리스트가 있으면 열마다 실제 좌석 번호(길이가 달라 가운데 맞춤), 없으면 열 × 열당 좌석 격자
    window.seatRows = (g) => g.layout
        ? g.layout.map(r => ({ n: r.n, label: String(r.label), seats: r.seats }))
        : Array.from({ length: g.rows }, (_, i) => ({ n: i + 1, label: String(i + 1), seats: Array.from({ length: g.per }, (_, j) => j + 1) }));
    window.seatGridHtml = (g, chosen) => {
        const esc = (s) => s.replace(/[&<>"']/g, ch => '&#' + ch.charCodeAt(0) + ';');
        let h = '';
        for (const r of window.seatRows(g)) {
            const label = esc(r.label);
            h += `<div class="seat-row${g.layout ? ' is-listed' : ''}"><span class="seat-row-no">${label}</span><span class="seat-row-seats">`;
            for (const n of r.seats) {
                const k = r.n + '-' + n, t = g.taken.has(k), c = chosen.includes(k);
                h += `<button type="button" data-seat="${k}" class="seat${t ? ' is-taken' : ''}${c ? ' is-chosen' : ''}"${t ? ' disabled' : ` aria-pressed="${c}"`} aria-label="${label}열 ${n}번${t ? ' 판매됨' : ''}">${n}</button>`;
            }
            h += `</span><span class="seat-row-no is-end">${label}</span></div>`;
        }
        return `<div class="seat-rows${g.layout ? ' is-listed' : ''}">${h}</div>`;
    };

    // 확대·축소·이동 (휠 · 두 손가락 · 끌기 · 버튼). 라이브러리 없이 transform 하나만 바꾼다(GPU, 좌석 수천 개도 가볍게).
    // 끌어서 움직였으면 그 클릭은 좌석 선택으로 치지 않는다(moved)
    window.panZoom = () => ({
        s: 1, x: 0, y: 0, min: .3, max: 4, moved: false, pts: new Map(), last: null, pinch: null,
        get tf() { return `translate3d(${this.x}px, ${this.y}px, 0) scale(${this.s})` },
        zoomAt(f, cx, cy) {
            const s = Math.min(this.max, Math.max(this.min, this.s * f)); f = s / this.s;
            this.x = cx - (cx - this.x) * f; this.y = cy - (cy - this.y) * f; this.s = s;
        },
        zoomBtn(f) { const r = this.$refs.vp.getBoundingClientRect(); this.zoomAt(f, r.width / 2, r.height / 2) },
        fit() {
            this.$nextTick(() => requestAnimationFrame(() => {
                const vp = this.$refs.vp, c = this.$refs.content; if (!vp || !c || !vp.clientWidth) return;
                const cw = c.scrollWidth, ch = c.scrollHeight;
                this.s = Math.min(vp.clientWidth / cw, vp.clientHeight / ch, 1.6) * .94;
                this.x = (vp.clientWidth - cw * this.s) / 2; this.y = (vp.clientHeight - ch * this.s) / 2;
            }));
        },
        wheel(e) { const r = this.$refs.vp.getBoundingClientRect(); this.zoomAt(e.deltaY < 0 ? 1.15 : 1 / 1.15, e.clientX - r.left, e.clientY - r.top) },
        down(e) { this.pts.set(e.pointerId, { x: e.clientX, y: e.clientY }); this.last = { x: e.clientX, y: e.clientY }; this.moved = false; this.pinch = null },
        move(e) {
            if (!this.pts.has(e.pointerId)) return;
            this.pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
            if (this.pts.size === 2) {
                const [a, b] = [...this.pts.values()], d = Math.hypot(a.x - b.x, a.y - b.y), r = this.$refs.vp.getBoundingClientRect();
                if (this.pinch) this.zoomAt(d / this.pinch, (a.x + b.x) / 2 - r.left, (a.y + b.y) / 2 - r.top);
                this.pinch = d; this.moved = true; return;
            }
            const dx = e.clientX - this.last.x, dy = e.clientY - this.last.y;
            if (!this.moved && Math.hypot(dx, dy) < 5) return;
            this.moved = true; this.x += dx; this.y += dy; this.last = { x: e.clientX, y: e.clientY };
        },
        up(e) { this.pts.delete(e.pointerId); if (this.pts.size < 2) this.pinch = null; setTimeout(() => this.moved = false, 0) },
    });
</script>

<div class="tix-wrap mt-5 lg:mt-6"
     x-data="{
        sections: @js($sections),
        selected: @js((int) old('section_id') ?: null),
        qty: @js((int) old('qty', 1)),
        max: {{ $event->max_per_buyer }},
        step: @js($errors->has('agree_terms') || $errors->has('coupon_issue_id') ? 2 : 1),
        view: 'map',
        secs: 600, expired: false,
        get section() { return this.sections.find(s => s.id === this.selected) },
        get limit() { return this.section ? Math.min(this.max, this.section.left) : this.max },
        get ready() { return this.section && (this.section.grid ? this.chosen.length > 0 : this.qty > 0) },
        get clock() { return Math.floor(this.secs / 60) + ':' + String(this.secs % 60).padStart(2, '0') },
        pick(id) {
            if (this.selected !== id) { this.chosen = []; this.step = 1 }
            this.selected = id;
            if (this.section?.grid) { this.qty = 0; this.view = 'seats'; this.loadSeats(); return }
            this.qty = Math.max(1, Math.min(this.qty || 1, this.limit));
        },
        // ── 좌석 고르기 (팔린 좌석은 회색) ──
        seatsUrl: @js(route('concerts.seats', [$event, 0])),
        grid: null, chosen: [], seatMsg: '', loadingSeats: false,
        async loadSeats() {
            this.grid = null; this.seatMsg = ''; this.loadingSeats = true;
            try {
                const r = await fetch(this.seatsUrl.replace('/sections/0/', '/sections/' + this.selected + '/'), { headers: { Accept: 'application/json' } });
                if (!r.ok) throw new Error();
                const d = await r.json();
                this.grid = { rows: d.rows, per: d.per_row, layout: d.layout, taken: new Set(d.taken),
                    labels: Object.fromEntries((d.layout || []).map(r => [r.n, String(r.label)])) };
                this.section.left = d.left;
            } catch (e) { this.seatMsg = '좌석을 불러오지 못했어요. 구역을 다시 눌러 주세요.' }
            this.loadingSeats = false;
            this.$nextTick(() => { this.drawSeats(); this.$dispatch('pz-fit') });
        },
        drawSeats() { const el = document.getElementById('seat-canvas'); if (this.grid && el) el.innerHTML = window.seatGridHtml(this.grid, this.chosen) },
        toggleSeat(e) {
            const b = e.target.closest('[data-seat]'); if (!b || b.disabled) return;
            const k = b.dataset.seat, i = this.chosen.indexOf(k);
            if (i >= 0) { this.chosen.splice(i, 1); b.classList.remove('is-chosen'); b.setAttribute('aria-pressed', 'false') }
            else if (this.chosen.length >= this.limit) { this.seatMsg = '한 번에 ' + this.limit + '석까지 고를 수 있어요.'; return }
            else { this.chosen.push(k); b.classList.add('is-chosen'); b.setAttribute('aria-pressed', 'true') }
            this.seatMsg = ''; this.qty = this.chosen.length;
        },
        removeSeat(k) { this.chosen = this.chosen.filter(x => x !== k); this.qty = this.chosen.length; this.drawSeats(); if (!this.chosen.length) this.step = 1 },
        clearAll() { this.chosen = []; this.qty = this.section && !this.section.grid ? 1 : 0; this.step = 1; this.drawSeats() },
        seatLabel(k) { const [r, n] = k.split('-'); return ((this.grid && this.grid.labels[r]) || r) + '열 ' + n + '번' },
        tick() {
            if (this.secs > 0) { this.secs--; return }
            this.expired = true; this.chosen = []; this.step = 1; this.secs = 600; this.drawSeats();
        },
        coupons: @js($myCoupons->map(fn ($i) => ['id' => $i->id, 'label' => $i->coupon->benefitLabel(), 'kind' => $i->coupon->kind, 'value' => $i->coupon->value, 'max' => $i->coupon->max_discount, 'min' => $i->coupon->min_amount])->values()),
        couponId: @js(old('coupon_issue_id', '')),
        balance: {{ (int) ($customer?->mileage_balance ?? 0) }},
        mileage: @js((int) old('mileage_use', 0)),
        get total() { return this.section ? this.section.price * this.qty : 0 },
        get discount() {
            const c = this.coupons.find(c => c.id == this.couponId); if (!c || this.total < c.min) return 0;
            let d = c.kind === 'fixed' ? c.value : Math.floor(this.total * c.value / 1000) * 10;
            if (c.max) d = Math.min(d, c.max);
            return Math.min(d, this.total);
        },
        get mileageCap() { return Math.min(this.balance, Math.floor((this.total - this.discount) * {{ (int) \App\Models\Setting::get('mileage_use_max_rate') }} / 100)) },
        get mileageUse() { const m = Math.min(Number(this.mileage) || 0, this.mileageCap); return m >= {{ (int) \App\Models\Setting::get('mileage_min_use') }} ? m : 0 },
     }"
     x-init="setInterval(() => tick(), 1000); selected && pick(selected)">

    {{-- ── 위: 공연 · 회차 · 남은 시간 ── --}}
    <header class="flex flex-wrap items-center gap-x-5 gap-y-3 rounded-[22px] bg-white px-4 py-3.5 ring-1 ring-ink/[.08] lg:px-5">
        @if ($p)
            <a href="{{ route('shows.show', $p) }}" class="hidden w-11 shrink-0 sm:block" aria-label="작품 정보로">@include('partials.poster', ['p' => $p, 'eager' => true])</a>
        @endif
        <div class="min-w-0 flex-1">
            <h1 class="truncate text-[17px] font-black tracking-tight lg:text-xl">{{ $p?->title ?? $event->name }}</h1>
            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-mist">
                <span>{{ $event->venue }}</span>
                @if ($rounds->count() > 1)
                    <label class="sr-only" for="round">회차 바꾸기</label>
                    <select id="round" onchange="location.href = this.value" class="num rounded-full bg-paper py-1 pl-3 pr-8 text-[13px] font-bold text-ink ring-1 ring-ink/10">
                        @foreach ($rounds as $r)
                            <option value="{{ route('concerts.show', $r) }}" @selected($r->id === $event->id)>{{ $when($r) }}</option>
                        @endforeach
                    </select>
                @else
                    <span class="num font-bold text-ink">{{ $when($event) }}</span>
                @endif
            </div>
        </div>
        <p class="flex items-center gap-2 text-[13px] font-semibold text-mist" role="timer" aria-live="off">
            좌석 선택 시간
            <span class="num min-w-[52px] rounded-full bg-paper px-2.5 py-1 text-center text-[15px] font-bold" :class="secs < 60 ? 'text-signal' : 'text-ink'" x-text="clock">10:00</span>
        </p>
    </header>

    <p x-show="expired" x-cloak role="alert" class="mt-3 flex items-center justify-between rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal">
        좌석 선택 시간이 지나 고른 좌석을 비웠어요. 다시 골라 주세요.
        <button type="button" @click="expired = false" class="text-xs underline">닫기</button>
    </p>
    @error('section_id')
        <p role="alert" class="mt-3 rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal">{{ $message }}</p>
    @enderror

    <div class="mt-4 lg:grid lg:grid-cols-[minmax(0,1fr)_360px] lg:gap-6">
        {{-- ── 왼쪽: 좌석도 → 구역 좌석 ── --}}
        <section class="min-w-0 rounded-[24px] bg-paper/60 p-3 ring-1 ring-ink/[.06] sm:p-4" aria-labelledby="seat-title">
            <div class="flex items-center justify-between gap-2 px-1">
                <h2 id="seat-title" class="text-[15px] font-bold">
                    <span x-show="view === 'map'">구역 고르기</span>
                    <span x-show="view === 'seats'" x-cloak><span class="num" x-text="section?.code"></span>구역 좌석 <span class="text-mist" x-text="section ? '· ' + section.tier + '석' : ''"></span></span>
                </h2>
                <button type="button" x-show="view === 'seats'" x-cloak @click="view = 'map'; $dispatch('pz-fit')" class="rounded-full bg-white px-3.5 py-1.5 text-[13px] font-semibold ring-1 ring-ink/10 hover:ring-ink">← 전체 좌석도</button>
            </div>

            {{-- 전체 좌석도 (크게 · 확대/축소/이동): 구역을 누르면 그 구역 좌석으로 --}}
            <div x-show="view === 'map'" class="mt-3">
                <div class="pz" x-data="panZoom()" x-init="fit()" @pz-fit.window="fit()" @resize.window.debounce.200ms="fit()">
                    <div x-ref="vp" class="pz-vp" @wheel.prevent="wheel($event)" @pointerdown="down($event)" @pointermove="move($event)" @pointerup="up($event)" @pointercancel="up($event)" @click.capture="moved && ($event.stopPropagation(), $event.preventDefault())">
                        <div x-ref="content" class="pz-content w-[900px]" :style="'transform:' + tf">
                            @if ($hasMap)
                                @include('partials.seatmap', ['event' => $event, 'interactive' => true])
                            @else
                                @include('partials.bowl', ['event' => $event, 'interactive' => true, 'tierColors' => $tierColors])
                            @endif
                        </div>
                    </div>
                    @include('partials.pz-controls')
                </div>
                <ul class="mt-4 grid grid-cols-3 gap-2 text-sm sm:grid-cols-4 lg:grid-cols-6">
                    <template x-for="s in sections" :key="s.id">
                        <li>
                            <button type="button" @click="s.left && pick(s.id)" :disabled="!s.left"
                                    class="w-full rounded-xl bg-white px-2.5 py-2 text-left ring-1 transition disabled:opacity-40"
                                    :class="selected === s.id ? 'ring-2 ring-ink' : 'ring-ink/10 hover:ring-ink/40'">
                                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" :style="'background:' + s.color"></span><span class="num font-bold" x-text="s.code"></span></span>
                                <span class="mt-0.5 block text-xs" :class="s.left ? 'text-mist' : 'font-semibold text-signal'" x-text="s.left ? won(s.left) + '석' : '매진'"></span>
                            </button>
                        </li>
                    </template>
                </ul>
            </div>

            {{-- 구역 좌석 --}}
            <div x-show="view === 'seats'" x-cloak class="mt-3">
                <div class="flex flex-wrap gap-3 px-1 text-xs text-mist">
                    <span class="flex items-center gap-1.5"><span class="seat-key" :style="'--tier:' + (section?.color || '#8B5CF6')"></span>선택 가능</span>
                    <span class="flex items-center gap-1.5"><span class="seat-key is-chosen"></span>내가 고른 좌석</span>
                    <span class="flex items-center gap-1.5"><span class="seat-key is-taken"></span>판매됨</span>
                    <span class="ml-auto">한 번에 <b class="num text-ink" x-text="limit"></b>석까지</span>
                </div>
                <p x-show="loadingSeats" class="mt-6 text-center text-sm text-mist">좌석을 불러오는 중…</p>
                {{-- 구역 좌석 (크게 · 확대/축소/이동) --}}
                <div class="pz mt-3" x-data="panZoom()" @pz-fit.window="fit()" @resize.window.debounce.200ms="fit()">
                    <div x-ref="vp" class="pz-vp" @wheel.prevent="wheel($event)" @pointerdown="down($event)" @pointermove="move($event)" @pointerup="up($event)" @pointercancel="up($event)" @click.capture="moved && ($event.stopPropagation(), $event.preventDefault())">
                        <div x-ref="content" class="pz-content p-6" :style="'transform:' + tf + '; --tier:' + (section?.color || '#8B5CF6')">
                            <div class="seat-stage mx-auto mb-6">STAGE</div>
                            <div id="seat-canvas" @click="toggleSeat($event)" role="group" aria-label="좌석"></div>
                        </div>
                    </div>
                    @include('partials.pz-controls')
                </div>
                <p x-show="seatMsg" x-text="seatMsg" role="status" class="mt-2 px-1 text-sm font-semibold text-signal"></p>
            </div>
        </section>

        {{-- ── 오른쪽: 선택 좌석 → 할인·결제 ── --}}
        <form method="POST" action="{{ route('concerts.purchase', $event) }}" id="pay" class="mt-5 scroll-mt-32 lg:mt-0">
            @csrf
            <input type="hidden" name="section_id" :value="selected">
            <input type="hidden" name="qty" :value="qty">
            <template x-if="section && section.grid"><div><template x-for="k in chosen" :key="k"><input type="hidden" name="seats[]" :value="k"></template></div></template>

            <div class="rounded-[24px] bg-white p-5 ring-1 ring-ink/[.08] lg:sticky lg:top-[136px]">
                {{-- 1단계: 선택 좌석 --}}
                <div x-show="step === 1">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-black">선택 좌석 <span class="num text-stick" x-text="section && section.grid ? chosen.length : (section ? qty : 0)"></span></h2>
                        <button type="button" x-show="chosen.length" x-cloak @click="clearAll()" class="text-[13px] font-semibold text-mist hover:text-ink">전체 삭제</button>
                    </div>

                    <div class="mt-4 min-h-[120px]">
                        <template x-if="!section">
                            <p class="grid h-[120px] place-items-center rounded-2xl bg-paper text-sm text-mist">좌석도에서 구역을 골라 주세요</p>
                        </template>
                        {{-- 좌석을 직접 고르는 구역 --}}
                        <template x-if="section && section.grid">
                            <div>
                                <p x-show="!chosen.length" class="grid h-[120px] place-items-center rounded-2xl bg-paper text-sm text-mist">좌석을 눌러 골라 주세요</p>
                                <ul class="space-y-2">
                                    <template x-for="k in chosen" :key="k">
                                        <li class="flex items-center gap-3 rounded-2xl bg-paper px-4 py-3">
                                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="'background:' + section.color"></span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block text-[15px] font-bold" x-text="section.tier + '석'"></span>
                                                <span class="num block text-[13px] text-mist" x-text="section.code + '구역 ' + seatLabel(k)"></span>
                                            </span>
                                            <span class="num text-[15px] font-bold" x-text="won(section.price) + '원'"></span>
                                            <button type="button" @click="removeSeat(k)" class="grid h-7 w-7 place-items-center rounded-full text-mist hover:bg-white hover:text-ink" :aria-label="seatLabel(k) + ' 빼기'">×</button>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </template>
                        {{-- 자동 배정 구역: 매수만 --}}
                        <template x-if="section && !section.grid">
                            <div class="rounded-2xl bg-paper px-4 py-4">
                                <p class="flex items-center justify-between text-[15px] font-bold"><span x-text="section.code + '구역 · ' + section.tier + '석'"></span><span class="num" x-text="won(section.price) + '원'"></span></p>
                                <p class="mt-1 text-xs text-mist">좌석 번호는 결제하면 앞쪽부터 자동으로 정해져요.</p>
                                <div class="mt-3 flex items-center justify-between">
                                    <span class="text-sm font-semibold">매수 <span class="font-normal text-mist">(1인 {{ $event->max_per_buyer }}매까지)</span></span>
                                    <div class="flex items-center gap-1 rounded-full bg-white p-1 ring-1 ring-ink/10">
                                        <button type="button" class="size-8 rounded-full text-lg font-bold disabled:opacity-30" @click="qty--" :disabled="qty <= 1" aria-label="한 장 빼기">−</button>
                                        <span class="num w-7 text-center font-bold" x-text="qty"></span>
                                        <button type="button" class="size-8 rounded-full text-lg font-bold disabled:opacity-30" @click="qty++" :disabled="qty >= limit" aria-label="한 장 더하기">+</button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <details class="mt-4 rounded-2xl ring-1 ring-ink/[.08]" open>
                        <summary class="cursor-pointer px-4 py-3 text-sm font-bold">등급별 가격 · 남은 좌석</summary>
                        <ul class="space-y-2 px-4 pb-4 text-sm">
                            @foreach ($tiers as $t)
                                <li class="flex items-center gap-2">
                                    <span class="h-2.5 w-2.5 rounded-full" style="background: {{ $t['color'] }}"></span>
                                    <span class="font-semibold">{{ $t['name'] }}석</span>
                                    <span class="num text-xs text-mist">{{ number_format($t['left']) }}석</span>
                                    <span class="num ml-auto font-bold">{{ number_format($t['price']) }}원</span>
                                </li>
                            @endforeach
                        </ul>
                    </details>

                    @if (! $event->salesOpen())
                        <p class="mt-5 grid h-14 place-items-center rounded-2xl bg-paper text-[15px] font-bold text-mist">지금은 예매 기간이 아니에요</p>
                    @else
                        <button type="button" @click="ready && (step = 2, $nextTick(() => document.getElementById('pay').scrollIntoView({ behavior: 'smooth', block: 'start' })))" :disabled="!ready"
                                class="mt-5 flex h-14 w-full items-center justify-center rounded-2xl bg-ink text-[17px] font-bold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-35">
                            <span x-text="ready ? '선택 완료 · ' + won(total) + '원' : (section ? '좌석을 골라 주세요' : '구역을 골라 주세요')">선택 완료</span>
                        </button>
                    @endif
                </div>

                {{-- 2단계: 예매자 · 할인 · 동의 · 결제 --}}
                <div x-show="step === 2" x-cloak>
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-black">할인 · 결제</h2>
                        <button type="button" @click="step = 1" class="text-[13px] font-semibold text-mist hover:text-ink">‹ 좌석 다시 고르기</button>
                    </div>
                    <p class="mt-3 rounded-2xl bg-paper px-4 py-3 text-sm">
                        <span class="font-bold" x-text="section ? section.tier + '석 · ' + section.code + '구역' : ''"></span>
                        <span class="num block text-[13px] text-mist" x-text="section && section.grid ? chosen.map(seatLabel).join(', ') : qty + '매'"></span>
                    </p>

                    {{-- 예매는 회원만: 예매자 = 로그인한 회원 (1인 매수 제한을 계정 단위로 지켜 리셀을 막는다) --}}
                    @if ($member)
                        <div class="mt-3 flex items-center justify-between rounded-2xl bg-paper px-4 py-3 text-sm">
                            <span>예매자 <b>{{ $member->name }}</b> <span class="num text-mist">{{ $member->maskedPhone() }}</span></span>
                            <a href="{{ route('member.account') }}" class="text-xs font-semibold text-stick">내 계정</a>
                        </div>
                    @else
                        <div class="mt-3 rounded-2xl bg-paper px-4 py-4 text-sm">
                            <p class="font-bold">티켓 예매는 회원만 할 수 있어요</p>
                            <p class="mt-1 text-xs text-mist">{{ __('해외 팬도 가입할 수 있어요 (중국은 이메일 인증)') }}</p>
                        </div>
                    @endif

                    {{-- 혜택: 쿠폰 · 마일리지 --}}
                    <div class="mt-3 rounded-2xl bg-paper p-4 text-sm">
                        @if ($customer)
                            <p class="mb-2 font-bold">혜택 <span class="font-normal text-mist">마일리지 <span class="num">{{ number_format($customer->mileage_balance) }}</span>원</span></p>
                            <div class="grid grid-cols-2 gap-2">
                                <select name="coupon_issue_id" x-model="couponId" class="field !py-2 text-xs" aria-label="쿠폰">
                                    <option value="">쿠폰 안 씀</option>
                                    <template x-for="c in coupons" :key="c.id"><option :value="c.id" x-text="c.label"></option></template>
                                </select>
                                <input type="number" name="mileage_use" min="0" step="100" x-model="mileage" class="field num !py-2 text-xs" :placeholder="'마일리지 (최대 ' + won(mileageCap) + ')'" aria-label="마일리지 사용">
                            </div>
                            <p x-show="couponId && total && !discount" class="mt-1 text-xs text-signal">이 예매에는 이 쿠폰을 쓸 수 없어요 (최소 금액 확인)</p>
                        @else
                            <p class="text-mist">로그인하면 쿠폰·마일리지를 쓸 수 있어요.</p>
                        @endif
                        @foreach ($freeCoupons as $c)
                            <p class="mt-2 text-xs text-mist">받을 수 있는 쿠폰: <b class="text-ink">{{ $c->benefitLabel() }}</b> ({{ $c->name }}) — <a href="{{ route('benefits') }}" class="row-link text-ink">혜택에서 받기</a></p>
                        @endforeach
                        @error('coupon_issue_id')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
                    </div>

                    {{-- 결제 금액 --}}
                    <dl class="mt-4 space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-mist">티켓 금액</dt><dd class="num" x-text="won(total) + '원'"></dd></div>
                        <div class="flex justify-between" x-show="discount"><dt class="text-mist">쿠폰 할인</dt><dd class="num text-signal" x-text="'-' + won(discount) + '원'"></dd></div>
                        <div class="flex justify-between" x-show="mileageUse"><dt class="text-mist">마일리지</dt><dd class="num text-signal" x-text="'-' + won(mileageUse) + '원'"></dd></div>
                        <div class="flex items-baseline justify-between border-t border-ink/10 pt-2"><dt class="font-bold">결제 금액</dt><dd class="num text-xl font-black" x-text="won(total - discount - mileageUse) + '원'"></dd></div>
                    </dl>

                    <div class="mt-4 space-y-2 rounded-2xl bg-paper p-4 text-xs">
                        <p class="font-bold text-ink">취소 수수료</p>
                        <p class="text-mist">관람일 10일 전까지 없음 · 9~7일 전 10% · 6~3일 전 20% · 2~1일 전 30% · 당일 취소 불가
                            <a href="{{ route('policy', 'refund') }}" target="_blank" class="row-link text-ink">자세히</a></p>
                        <label class="flex items-start gap-2 pt-1 text-sm text-ink">
                            <input type="checkbox" name="agree_terms" value="1" class="mt-0.5" @checked(old('agree_terms')) required>
                            <span><a href="{{ route('policy', 'terms') }}" target="_blank" class="row-link">이용약관</a>·<a href="{{ route('policy', 'privacy') }}" target="_blank" class="row-link">개인정보 수집·이용</a>·취소 수수료에 동의해요 (필수)</span>
                        </label>
                        @error('agree_terms')<p class="text-sm text-signal">{{ $message }}</p>@enderror
                    </div>

                    @if ($event->salesOpen() && ! $member)
                        <a href="{{ route('member.login', ['return' => request()->getRequestUri()]) }}" class="mt-5 flex h-14 w-full items-center justify-center rounded-2xl bg-ink text-[17px] font-bold text-white">로그인하고 예매하기</a>
                    @elseif ($event->salesOpen())
                        <button class="mt-5 flex h-14 w-full items-center justify-center rounded-2xl bg-pick text-[17px] font-bold text-ink transition hover:brightness-95 disabled:opacity-40" :disabled="!ready">
                            <span x-text="won(total - discount - mileageUse) + '원 결제하기'">결제하기</span>
                        </button>
                    @endif
                </div>
            </div>
        </form>
    </div>

    {{-- 휴대폰: 고른 좌석 수 + 다음 (오른쪽 패널로) --}}
    <div x-show="step === 1 && ready" x-cloak class="fixed inset-x-0 bottom-[76px] z-30 px-4 lg:hidden">
        <a href="#pay" class="mx-auto flex max-w-md items-center justify-between rounded-2xl bg-pick px-5 py-3.5 text-[15px] font-bold text-ink shadow-lg shadow-ink/30">
            <span x-text="(section && section.grid ? chosen.length : qty) + '석 선택'"></span><span class="num" x-text="won(total) + '원 ›'"></span>
        </a>
    </div>
</div>

<style>
    .pz { position: relative }
    .pz-vp { position: relative; height: min(70vh, 680px); min-height: 360px; overflow: hidden; border-radius: 20px; touch-action: none; cursor: grab;
        background: var(--color-white); box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--color-ink) 8%, transparent); user-select: none }
    .pz-vp:active { cursor: grabbing }
    .pz-content { position: absolute; left: 0; top: 0; transform-origin: 0 0; will-change: transform; width: max-content }
    .pz-content.w-[900px] { width: 900px }
    .seat-stage { width: min(70%, 420px); padding: 8px 0; border-radius: 0 0 40px 40px; text-align: center; font: 800 12px/1 var(--font-display); letter-spacing: .3em;
        color: var(--color-mist); background: color-mix(in oklab, var(--color-ink) 10%, transparent) }
    .seat-rows { display: flex; flex-direction: column; align-items: center; width: max-content; min-width: 100%; margin: 0 auto }
    .seat-row { display: flex; align-items: center; gap: 4px; margin-bottom: 4px; width: max-content }
    .seat-row-seats { display: flex; gap: 4px }
    .seat-row-no { width: 1.8rem; flex: none; font: 700 10px/1 var(--font-display); color: var(--color-mist); text-align: right; padding-right: 4px }
    .seat-row-no.is-end { text-align: left; padding: 0 0 0 4px }
    .seat, .seat-key { --s: var(--seat, 30px); width: var(--s); height: var(--s); flex: none; border-radius: 30% 30% 22% 22%;
        background: color-mix(in oklab, var(--tier, #8B5CF6) 22%, var(--color-white)); box-shadow: inset 0 0 0 1.5px var(--tier, #8B5CF6);
        font: 700 calc(var(--s) * .36)/var(--s) var(--font-display); color: color-mix(in oklab, var(--tier, #8B5CF6) 70%, var(--color-ink)); text-align: center; transition: transform .1s, background .15s }
    .seat-key { display: inline-block; --s: 14px; border-radius: 4px; font-size: 0 }
    .seat:not(:disabled):hover { background: color-mix(in oklab, var(--tier, #8B5CF6) 45%, var(--color-white)) }
    .seat:not(:disabled):active { transform: scale(.9) }
    .seat.is-chosen, .seat-key.is-chosen { background: var(--color-ink); color: var(--color-white); box-shadow: 0 0 0 2px var(--color-pick) }
    .seat.is-taken, .seat-key.is-taken { background: color-mix(in oklab, var(--color-ink) 12%, transparent); box-shadow: none; color: transparent; cursor: not-allowed }
</style>
@endsection
