@extends('layouts.backoffice')
@section('title', $map->name)
@section('eyebrow', '공연장 좌석표 · '.$map->venue)

@php($initial = old('payload') ? json_decode(old('payload'), true) : [
    'tiers' => $map->tiers,
    'sections' => $map->sections->map(fn ($s) => [
        'code' => $s->code, 'tier' => $s->tier, 'x' => $s->x, 'y' => $s->y, 'w' => $s->w, 'h' => $s->h,
        'rows' => $s->rows, 'seats_per_row' => $s->seats_per_row, 'listed' => $s->layout ? $s->capacity() : null,
    ])->values(),
    'crop' => ['x' => $map->crop_x, 'y' => $map->crop_y, 'w' => $map->crop_w, 'h' => $map->crop_h],
] + ['venueSeats' => (int) ($map->venueRecord?->seats ?? 0)])

@section('content')
<script>
    // 좌석표 편집기 — 이미지 위 구역 박스(0~1 비율 좌표) 그리기·옮기기·크기 조절 + 등급·열·좌석
    window.seatMapEditor = (initial) => ({
        tiers: initial.tiers || [],
        sections: initial.sections || [],
        sel: null,               // 고른 구역 순번
        mode: 'select',          // select | draw | crop
        crop: initial.crop || { x: 0, y: 0, w: 1, h: 1 },   // 팬에게 보여줄 영역 (범례·요약표·제목 제외)
        get cropped() { return this.crop.w < 0.999 || this.crop.h < 0.999 },
        resetCrop() { this.crop = { x: 0, y: 0, w: 1, h: 1 }; this.touch() },
        cropBox() { const c = this.crop; return `left:${c.x * 100}%;top:${c.y * 100}%;width:${c.w * 100}%;height:${c.h * 100}%` },
        zoom: 1,
        activeTier: (initial.tiers && initial.tiers[0]) ? initial.tiers[0].key : null,
        q: '',
        drag: null,
        dirty: false,
        get current() { return this.sel === null ? null : this.sections[this.sel] },
        seatsOf(s) { return s.listed || s.rows * s.seats_per_row },   // 좌석 리스트가 있으면 실제 좌석 수
        get totalSeats() { return this.sections.reduce((n, s) => n + this.seatsOf(s), 0) },
        venueSeats: initial.venueSeats || 0,   // 공연장(KOPIS) 객석 수
        get seatGap() { return this.venueSeats && this.totalSeats ? Math.round(Math.abs(this.totalSeats - this.venueSeats) / this.venueSeats * 100) : 0 },
        get filtered() {
            const q = this.q.trim().toUpperCase();
            return this.sections.map((s, i) => ({ s, i })).filter(({ s }) => !q || s.code.toUpperCase().includes(q));
        },
        tierOf(key) { return this.tiers.find(t => t.key === key) || { name: '?', color: '#999999', price: 0, seats: 0 } },
        tierSeats(key) { return this.sections.filter(s => s.tier === key).reduce((n, s) => n + this.seatsOf(s), 0) },
        tierCount(key) { return this.sections.filter(s => s.tier === key).length },
        touch() { this.dirty = true },
        addTier() {
            const n = Math.max(0, ...this.tiers.map(t => parseInt(t.key.slice(1)) || 0)) + 1;
            this.tiers.push({ key: 't' + n, name: '등급 ' + n, color: '#6C4BFF', price: 0, seats: 0 });
            this.activeTier = 't' + n; this.touch();
        },
        removeTier(i) {
            const key = this.tiers[i].key;
            if (this.tierCount(key)) { alert('이 등급을 쓰는 구역이 있어요. 구역의 등급을 먼저 바꿔 주세요.'); return }
            if (this.tiers.length === 1) return;
            this.tiers.splice(i, 1);
            if (this.activeTier === key) this.activeTier = this.tiers[0].key;
            this.touch();
        },
        // 등급 좌석 수(범례)를 그 등급 구역들에 고르게 나누기 — 열당 최대 20석
        distribute() {
            if (!confirm('등급마다 범례 좌석 수를 구역에 고르게 나눠 열 × 열당 좌석을 다시 정할까요? 직접 고친 값이 바뀌어요.')) return;
            for (const t of this.tiers) {
                const list = this.sections.filter(s => s.tier === t.key);
                if (!t.seats || !list.length) continue;
                const per = Math.ceil(t.seats / list.length);
                for (const s of list.filter(s => !s.listed)) { s.seats_per_row = Math.min(20, Math.max(1, per)); s.rows = Math.min(300, Math.ceil(per / s.seats_per_row)) }
            }
            this.touch();
        },
        nextCode() {
            const used = new Set(this.sections.map(s => s.code.toUpperCase()));
            let n = this.sections.length + 1; while (used.has('S' + n)) n++;
            return 'S' + n;
        },
        remove(i) {
            if (!confirm(this.sections[i].code + ' 구역을 지울까요?')) return;
            this.sections.splice(i, 1); this.sel = null; this.touch();
        },
        // ── 이미지 위 마우스/터치 ──
        point(e) {
            const r = this.$refs.stage.getBoundingClientRect();
            return { x: Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)), y: Math.min(1, Math.max(0, (e.clientY - r.top) / r.height)) };
        },
        down(e, i = null, handle = false) {
            const p = this.point(e);
            if (this.mode === 'crop') {
                this.crop = { x: p.x, y: p.y, w: 0, h: 0 };
                this.drag = { kind: 'crop', ox: p.x, oy: p.y };
            } else if (this.mode === 'draw' && i === null) {
                if (!this.activeTier) { alert('등급을 먼저 만들어 주세요.'); return }
                this.sections.push({ code: this.nextCode(), tier: this.activeTier, x: p.x, y: p.y, w: 0.001, h: 0.001, rows: 10, seats_per_row: 20 });
                this.sel = this.sections.length - 1;
                this.drag = { kind: 'draw', ox: p.x, oy: p.y };
            } else if (i !== null) {
                this.sel = i;
                const s = this.sections[i];
                this.drag = { kind: handle ? 'resize' : 'move', ox: p.x, oy: p.y, x: s.x, y: s.y, w: s.w, h: s.h };
            } else { this.sel = null; return }
            this.$refs.stage.setPointerCapture(e.pointerId);
            e.preventDefault();
        },
        move(e) {
            if (!this.drag) return;
            const p = this.point(e), d = this.drag;
            if (d.kind === 'crop') {
                this.crop = { x: Math.min(d.ox, p.x), y: Math.min(d.oy, p.y), w: Math.abs(p.x - d.ox), h: Math.abs(p.y - d.oy) };
                this.touch(); return;
            }
            if (this.sel === null) return;
            const s = this.sections[this.sel];
            if (d.kind === 'draw') {
                s.x = Math.min(d.ox, p.x); s.y = Math.min(d.oy, p.y); s.w = Math.abs(p.x - d.ox); s.h = Math.abs(p.y - d.oy);
            } else if (d.kind === 'move') {
                s.x = Math.min(1 - s.w, Math.max(0, d.x + p.x - d.ox)); s.y = Math.min(1 - s.h, Math.max(0, d.y + p.y - d.oy));
            } else {
                s.w = Math.max(0.005, Math.min(1 - s.x, d.w + p.x - d.ox)); s.h = Math.max(0.005, Math.min(1 - s.y, d.h + p.y - d.oy));
            }
            this.touch();
        },
        up() {
            if (this.drag?.kind === 'crop') {
                if (this.crop.w < 0.1 || this.crop.h < 0.1) this.crop = { x: 0, y: 0, w: 1, h: 1 };   // 너무 작게 잡으면 전체
                this.mode = 'select';
            }
            if (this.drag?.kind === 'draw' && this.sel !== null) {
                const s = this.sections[this.sel];
                if (s.w < 0.006 || s.h < 0.006) { this.sections.splice(this.sel, 1); this.sel = null }
                else { this.mode = 'select'; this.$nextTick(() => this.$refs.code?.select()) }
            }
            this.drag = null;
        },
        box(s) { return `left:${s.x * 100}%;top:${s.y * 100}%;width:${s.w * 100}%;height:${s.h * 100}%;--c:${this.tierOf(s.tier).color}` },
        payload() { return JSON.stringify({ tiers: this.tiers, sections: this.sections, crop: this.crop }) },
    });
</script>

<div x-data="seatMapEditor(@js($initial))" class="grid gap-6 2xl:grid-cols-[minmax(0,1fr)_440px]"
     @beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = '' }">

    {{-- 배치도 + 구역 박스 --}}
    <section class="min-w-0 rounded-3xl bg-white p-4 ring-1 ring-ink/5">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <div class="flex rounded-full bg-paper p-1 text-sm font-semibold" role="group" aria-label="편집 모드">
                <button type="button" class="rounded-full px-4 py-1.5" :class="mode === 'select' ? 'bg-ink text-white' : 'text-mist'" @click="mode = 'select'">선택·이동</button>
                <button type="button" class="rounded-full px-4 py-1.5" :class="mode === 'draw' ? 'bg-ink text-white' : 'text-mist'" @click="mode = 'draw'">＋ 구역 그리기</button>
                <button type="button" class="rounded-full px-4 py-1.5" :class="mode === 'crop' ? 'bg-ink text-white' : 'text-mist'" @click="mode = 'crop'">보여줄 영역 자르기</button>
            </div>
            <button type="button" x-show="cropped" x-cloak class="text-xs font-semibold text-mist underline" @click="resetCrop()">자르기 취소(전체 보이기)</button>
            <label x-show="mode === 'draw'" class="flex items-center gap-2 text-sm">
                <span class="text-mist">새 구역 등급</span>
                <select x-model="activeTier" class="field !w-auto !py-1.5 text-sm">
                    <template x-for="t in tiers" :key="t.key"><option :value="t.key" x-text="t.name"></option></template>
                </select>
            </label>
            <div class="ml-auto flex items-center gap-1 text-sm">
                <span class="text-mist">확대</span>
                <template x-for="z in [1, 1.5, 2, 3]" :key="z">
                    <button type="button" class="num rounded-full px-2.5 py-1" :class="zoom === z ? 'bg-ink text-white' : 'bg-paper'" @click="zoom = z" x-text="(z * 100) + '%'"></button>
                </template>
            </div>
        </div>
        <p class="mb-3 text-xs text-mist" x-text="mode === 'crop' ? '팬에게 보여줄 배치도 부분(무대·객석·게이트)을 끌어서 고르세요. 제목·범례·요약표는 빼면 잘려 나가요.' : (mode === 'draw' ? '배치도에서 구역 영역을 끌어 박스를 그리세요. 그린 뒤 오른쪽에서 구역 이름·열·좌석을 정해요.' : '박스를 눌러 고르고, 끌어서 옮기거나 오른쪽 아래 모서리를 끌어 크기를 맞추세요. 어둡게 덮인 부분은 팬에게 보이지 않아요.')"></p>

        <div class="max-h-[78vh] overflow-auto rounded-2xl bg-ink">
            <div x-ref="stage" class="relative touch-none select-none overflow-hidden" :style="`width:${zoom * 100}%`"
                 :class="mode === 'select' ? 'cursor-default' : 'cursor-crosshair'"
                 @pointerdown="down($event)" @pointermove="move($event)" @pointerup="up()" @pointercancel="up()">
                <img src="{{ $map->imageUrl() }}" alt="{{ $map->name }} 배치도" width="{{ $map->image_w }}" height="{{ $map->image_h }}" class="pointer-events-none block h-auto w-full" draggable="false">
                {{-- 보여줄 영역: 바깥은 어둡게 (팬 화면에서는 잘려 나감) --}}
                <div x-show="cropped || mode === 'crop'" class="pointer-events-none absolute z-20 rounded-sm border-2 border-dashed border-pick shadow-[0_0_0_9999px_rgba(10,8,20,.62)]" :style="cropBox()"></div>
                <template x-for="(s, i) in sections" :key="i">
                    <div class="absolute rounded-[3px] border-2" :style="box(s)"
                         :class="sel === i ? 'z-10 border-white bg-[color-mix(in_srgb,var(--c)_45%,transparent)] shadow-[0_0_0_2px_#17132B]' : 'border-[var(--c)] bg-[color-mix(in_srgb,var(--c)_22%,transparent)] hover:border-white'"
                         @pointerdown.stop="mode === 'select' ? down($event, i) : down($event)">
                        <span class="num pointer-events-none absolute left-0.5 top-0 rounded bg-ink/80 px-1 text-[10px] font-bold leading-4 text-white" x-text="s.code"></span>
                        <span x-show="sel === i && mode === 'select'" class="absolute -bottom-1.5 -right-1.5 size-3.5 cursor-se-resize rounded-sm border-2 border-ink bg-white"
                              @pointerdown.stop="down($event, i, true)" aria-label="크기 조절"></span>
                    </div>
                </template>
            </div>
        </div>
    </section>

    {{-- 오른쪽: 기본 정보 · 등급 · 구역 --}}
    <form method="POST" action="{{ route('admin.seat-maps.update', $map) }}" class="space-y-4" @submit="dirty = false">
        @csrf @method('PUT')
        <input type="hidden" name="payload" :value="payload()">

        @error('payload')<p role="alert" class="rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal">{{ $message }}</p>@enderror
        @if ($map->events_count)
            <p class="rounded-2xl bg-pick/30 px-4 py-3 text-xs">이 좌석표로 만든 공연이 <b>{{ $map->events_count }}개</b> 있어요. 여기서 고쳐도 이미 만든 공연의 구역·좌석은 바뀌지 않고, 새로 등록하는 공연부터 적용돼요.</p>
        @endif

        <section class="space-y-3 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label for="venue_id" class="label">공연장</label>
                    <select id="venue_id" name="venue_id" class="field !py-2.5" required>
                        @foreach ($venues as $v)
                            <option value="{{ $v->id }}" @selected((int) old('venue_id', $map->venue_id) === $v->id)>{{ $v->name }}</option>
                        @endforeach
                    </select></div>
                <div><label for="name" class="label">좌석표 이름</label><input id="name" name="name" class="field !py-2.5" value="{{ old('name', $map->name) }}" required></div>
            </div>
            <div class="grid grid-cols-2 gap-2 text-center">
                <div class="rounded-2xl bg-paper px-3 py-2"><p class="text-xs text-mist">구역</p><p class="num text-xl font-black" x-text="sections.length"></p></div>
                <div class="rounded-2xl bg-paper px-3 py-2"><p class="text-xs text-mist">좌석</p><p class="num text-xl font-black" x-text="won(totalSeats)"></p></div>
            </div>
            <p x-show="venueSeats" class="text-xs" :class="seatGap > 10 ? 'rounded-2xl bg-signal/10 px-3 py-2 font-semibold text-signal' : 'text-mist'">
                공연장 객석 <span class="num" x-text="won(venueSeats)"></span>석
                <span x-show="seatGap > 10" x-text="'— 좌석표와 ' + seatGap + '% 달라요. 열·좌석 수를 확인해 주세요 (무대 배치에 따라 다를 수 있어요)'"></span>
                <span x-show="seatGap <= 10">와 비슷해요</span>
            </p>
        </section>

        <section class="rounded-3xl bg-white p-5 ring-1 ring-ink/5">
            <div class="flex items-center justify-between">
                <h2 class="font-bold">등급 <span class="text-xs font-normal text-mist">색 · 가격 · 범례 좌석 수</span></h2>
                <button type="button" class="text-sm font-semibold text-stick" @click="addTier()">＋ 등급</button>
            </div>
            <ul class="mt-3 space-y-2">
                <template x-for="(t, i) in tiers" :key="t.key">
                    <li class="rounded-2xl bg-paper p-2.5">
                        <div class="flex items-center gap-2">
                            <input type="color" x-model="t.color" @input="touch()" class="size-9 shrink-0 cursor-pointer rounded-lg bg-white" :aria-label="t.name + ' 색'">
                            <input x-model="t.name" @input="touch()" maxlength="30" class="field !py-2 text-sm" aria-label="등급 이름">
                            <button type="button" class="shrink-0 px-1 text-mist hover:text-signal" @click="removeTier(i)" aria-label="등급 지우기">✕</button>
                        </div>
                        <div class="mt-2 grid grid-cols-2 gap-2">
                            <label class="text-[11px] text-mist">가격(원)<input type="number" min="0" step="1000" x-model.number="t.price" @input="touch()" class="field num mt-0.5 !py-1.5 text-sm"></label>
                            <label class="text-[11px] text-mist">범례 좌석 수<input type="number" min="0" x-model.number="t.seats" @input="touch()" class="field num mt-0.5 !py-1.5 text-sm"></label>
                        </div>
                        <p class="mt-1.5 text-[11px]" :class="t.seats && tierSeats(t.key) !== t.seats ? 'text-signal' : 'text-mist'">
                            구역 <span class="num" x-text="tierCount(t.key)"></span>개 · 만든 좌석 <span class="num font-bold" x-text="won(tierSeats(t.key))"></span>석
                            <span x-show="t.seats" x-text="' / 범례 ' + won(t.seats) + '석'"></span>
                        </p>
                    </li>
                </template>
            </ul>
            <button type="button" class="btn-ghost mt-3 w-full !py-2 text-sm" @click="distribute()">등급 좌석 수대로 구역에 나누기</button>
        </section>

        {{-- 고른 구역 --}}
        <section class="rounded-3xl bg-white p-5 ring-1 ring-ink/5" x-show="current" x-cloak>
            <template x-if="current">
                <div>
                    <div class="flex items-center justify-between">
                        <h2 class="font-bold">구역 <span class="num" x-text="current.code"></span></h2>
                        <button type="button" class="text-sm font-semibold text-signal" @click="remove(sel)">구역 지우기</button>
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <label class="text-[11px] text-mist">구역 이름<input x-ref="code" x-model="current.code" @input="touch()" maxlength="10" class="field num mt-0.5 !py-2 text-sm"></label>
                        <label class="text-[11px] text-mist">등급
                            <select x-model="current.tier" @change="touch()" class="field mt-0.5 !py-2 text-sm">
                                <template x-for="t in tiers" :key="t.key"><option :value="t.key" x-text="t.name" :selected="t.key === current.tier"></option></template>
                            </select>
                        </label>
                        <label x-show="!current.listed" class="text-[11px] text-mist">열 수<input type="number" min="1" max="300" x-model.number="current.rows" @input="touch()" class="field num mt-0.5 !py-2 text-sm"></label>
                        <label x-show="!current.listed" class="text-[11px] text-mist">열당 좌석<input type="number" min="1" max="200" x-model.number="current.seats_per_row" @input="touch()" class="field num mt-0.5 !py-2 text-sm"></label>
                    </div>
                    <p class="mt-2 text-sm">좌석 <b class="num" x-text="won(seatsOf(current))"></b>석 · <span class="num" x-text="won(tierOf(current.tier).price)"></span>원</p>
                    <p x-show="current.listed" class="mt-1 text-xs text-mist">좌석 리스트(CSV)로 넣은 실제 좌석이에요 (열 <span class="num" x-text="current.rows"></span>개). 바꾸려면 좌석 리스트를 다시 올리세요. 구역 이름을 바꾸면 좌석 리스트가 빠져요.</p>
                </div>
            </template>
        </section>

        {{-- 구역 목록 --}}
        <section class="rounded-3xl bg-white p-5 ring-1 ring-ink/5">
            <div class="flex items-center justify-between gap-2">
                <h2 class="font-bold">객석 목록</h2>
                <input x-model="q" placeholder="구역 찾기" class="field !w-32 !py-1.5 text-sm" aria-label="구역 찾기">
            </div>
            <p x-show="!sections.length" class="mt-3 text-sm text-mist">아직 구역이 없어요. 왼쪽에서 ‘＋ 구역 그리기’ 를 누르고 배치도 위에 박스를 그려 주세요.</p>
            <ul class="mt-3 max-h-80 divide-y divide-ink/5 overflow-auto text-sm">
                <template x-for="{ s, i } in filtered" :key="i">
                    <li>
                        <button type="button" class="flex w-full items-center gap-2 px-1 py-2 text-left hover:bg-paper" :class="sel === i && 'bg-paper'" @click="sel = i; mode = 'select'">
                            <span class="size-3 shrink-0 rounded-full" :style="`background:${tierOf(s.tier).color}`"></span>
                            <span class="num w-16 font-bold" x-text="s.code"></span>
                            <span class="min-w-0 flex-1 truncate text-xs text-mist" x-text="tierOf(s.tier).name"></span>
                            <span class="num text-xs" x-text="s.listed ? '좌석 리스트 ' + won(s.listed) : s.rows + '열 × ' + s.seats_per_row + ' = ' + won(seatsOf(s))"></span>
                        </button>
                    </li>
                </template>
            </ul>
        </section>

        <button class="btn-ink w-full">좌석표 저장</button>
        <p x-show="dirty" class="text-center text-xs font-semibold text-signal">저장하지 않은 변경이 있어요</p>
    </form>
</div>

{{-- 좌석 리스트(CSV): 기획사·공연장 좌석 목록으로 구역마다 실제 좌석(열마다 다른 좌석 수·번호) --}}
<form method="POST" action="{{ route('admin.seat-maps.seat-list', $map) }}" enctype="multipart/form-data" x-data="{ busy: false }" @submit="busy = true"
      class="mt-8 max-w-3xl rounded-3xl bg-white p-5 ring-1 ring-stick/30">
    @csrf
    <h2 class="font-bold">좌석 리스트 올리기 <span class="text-sm font-normal text-mist">실제 좌석 그대로</span></h2>
    <p class="mt-1 text-sm text-mist">기획사·공연장의 좌석 목록 엑셀을 <b>CSV 로 저장</b>해서 올리세요. 열: <b>구역, 열, 번호, 등급, 가격</b> (등급·가격은 없어도 돼요, 번호는 1-18 처럼 범위로 적어도 돼요).
        같은 이름 구역은 실제 좌석으로 바뀌고, 없는 구역은 새로 만들어져요. 이미 만든 공연은 바뀌지 않아요.
        <a href="{{ route('admin.seat-maps.seat-list-sample') }}" class="row-link text-ink">샘플 CSV 받기</a></p>
    <div class="mt-3 flex flex-wrap items-center gap-3">
        <input type="file" name="seat_list" accept=".csv,text/csv" required class="text-sm">
        <button class="btn-ink !py-2 text-sm" :disabled="busy" x-text="busy ? '읽는 중…' : '좌석 리스트 넣기'">좌석 리스트 넣기</button>
    </div>
    @error('seat_list')<p class="mt-2 text-sm text-signal">{{ $message }}</p>@enderror
</form>

{{-- 새 배치도 이미지 올리기 — AI 를 쓸 수 있으면 객석 목록·보여줄 영역을 새로 만든다 --}}
<form method="POST" action="{{ route('admin.seat-maps.image', $map) }}" enctype="multipart/form-data" x-data="{ busy: false }" @submit="busy = true"
      class="mt-8 max-w-3xl rounded-3xl bg-white p-5 ring-1 ring-ink/5">
    @csrf
    <h2 class="font-bold">배치도 이미지 바꾸기</h2>
    <p class="mt-1 text-sm text-mist">기획사가 새 좌석 배치도를 주면 올리세요.
        {{ $aiReady ? 'AI 로 읽기를 켜면 객석 목록과 보여줄 영역을 새 이미지로 다시 만들어요.' : '구역 박스는 새 이미지에 맞게 옮겨 주세요. (AI 판독을 쓰려면 서버에 ANTHROPIC_API_KEY 필요)' }}
        @if ($map->events_count) 이 좌석표를 쓰는 공연이 있어서, 새 이미지는 <b>새 좌석표</b>로 만들어져요 (기존 공연은 그대로). @else 이미 만든 공연은 바뀌지 않아요. @endif</p>
    <div class="mt-3 flex flex-wrap items-center gap-3">
        <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required class="text-sm">
        @if ($aiReady)
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="use_ai" value="1" checked> AI 로 읽기</label>
        @endif
        <button class="btn-ink !py-2 text-sm" :disabled="busy" x-text="busy ? '올리는 중…' : '새 이미지 올리기'">새 이미지 올리기</button>
    </div>
    @error('image')<p class="mt-2 text-sm text-signal">{{ $message }}</p>@enderror
</form>

<div class="mt-6 flex flex-wrap gap-3">
    <a href="{{ $map->venue_id ? route('admin.venues.show', $map->venue_id) : route('admin.venues.index') }}" class="btn-ghost !py-2.5 text-sm">공연장으로</a>
    @if ($aiReady)
        <form method="POST" action="{{ route('admin.seat-maps.read', $map) }}" onsubmit="return confirm('AI 로 다시 읽으면 지금 구역이 판독 결과로 바뀌어요. 계속할까요?')">
            @csrf
            <button class="btn-ghost !py-2.5 text-sm">AI 로 다시 읽기</button>
        </form>
    @endif
    @unless ($map->events_count)
        <form method="POST" action="{{ route('admin.seat-maps.destroy', $map) }}" onsubmit="return confirm('좌석표를 지울까요? 되돌릴 수 없어요.')">
            @csrf @method('DELETE')
            <button class="btn-ghost !py-2.5 text-sm text-signal">좌석표 지우기</button>
        </form>
    @endunless
</div>
@endsection
