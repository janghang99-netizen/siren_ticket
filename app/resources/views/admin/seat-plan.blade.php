@extends('layouts.backoffice')
@section('title', $map->name)
@section('eyebrow', '공연장 도면 · '.$map->venue)

@php($initial = [
    'name' => $map->name,
    'tiers' => $map->tiers,
    'plan' => $map->plan ?: ['stage' => ['x' => 300, 'y' => 40, 'w' => 400, 'h' => 90, 'rot' => 0, 'label' => 'STAGE'], 'objects' => [], 'labels' => []],
    'sections' => $sections,
    'image' => $map->imageUrl() ? ['url' => $map->imageUrl(), 'w' => $map->image_w, 'h' => $map->image_h] : null,
])

@section('actions')
    <a href="{{ route('admin.venues.show', $map->venue_id) }}" class="btn-ghost !py-2 text-sm">공연장으로</a>
@endsection

@section('content')
<script>
/**
 * 공연장 도면 편집기 — 캔버스(1000×1000)는 SVG 문자열로 한 번에 그리고(구역 수백·좌석 수만도 가볍게),
 * 마우스는 svg 하나에서 받는다. 좌석 위치는 window.SeatPlan(resources/js/seatplan.js) — 팬 좌석 화면과 같은 계산.
 */
window.planEditor = (init) => ({
    name: init.name, tiers: init.tiers, plan: init.plan, sections: init.sections, image: init.image,
    sel: null,                 // {kind:'stage'|'object'|'label'|'section', i}
    vb: { x: -40, y: -40, w: 1080 },
    snap: true, imgOpacity: .45, showSeats: true,
    polyDraft: null,           // 다각형 그리는 중: [[x,y],…]
    drag: null, saving: false, msg: '', err: '', dirty: false,

    init() {
        this.$watch(() => JSON.stringify([this.plan, this.sections, this.tiers, this.sel, this.polyDraft, this.imgOpacity, this.showSeats]), () => this.queue());
        this.queue();
        window.addEventListener('beforeunload', (e) => { if (this.dirty) { e.preventDefault(); e.returnValue = '' } });
    },

    // ── 그리기 ──
    _raf: 0,
    queue() { cancelAnimationFrame(this._raf); this._raf = requestAnimationFrame(() => this.draw()) },
    tier(key) { return this.tiers.find(t => t.key === key) || this.tiers[0] || { color: '#8A84A3', name: '?' } },
    draw() {
        const P = window.SeatPlan, esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => '&#' + c.charCodeAt(0) + ';');
        const isSel = (kind, i) => this.sel && this.sel.kind === kind && this.sel.i === i;
        let h = '';
        // 밑그림 (배치도 이미지): 캔버스 폭에 맞춘다
        if (this.image) {
            const hh = this.image.h && this.image.w ? 1000 * this.image.h / this.image.w : 1000;
            h += `<image href="${esc(this.image.url)}" x="0" y="0" width="1000" height="${hh}" opacity="${this.imgOpacity}" preserveAspectRatio="xMinYMin meet" pointer-events="none"/>`;
        }
        h += `<rect x="0" y="0" width="1000" height="1000" fill="none" stroke="currentColor" stroke-opacity=".12" stroke-dasharray="6 6" pointer-events="none"/>`;
        // 무대 · 물체
        const box = (o, kind, i, fill) => {
            const t = P.boxTransform(o);
            return `<g ${t ? `transform="${t}"` : ''}><rect data-kind="${kind}" data-i="${i}" x="${o.x}" y="${o.y}" width="${o.w}" height="${o.h}" rx="8" fill="${fill}" class="pe-hit ${isSel(kind, i) ? 'pe-sel' : ''}"/>`
                + `<text x="${o.x + o.w / 2}" y="${o.y + o.h / 2}" text-anchor="middle" dominant-baseline="central" font-size="${Math.min(34, o.h * .45)}" font-weight="800" class="pe-obj-text" pointer-events="none">${esc(o.label)}</text></g>`;
        };
        h += box(this.plan.stage, 'stage', 0, '#9AA0B8');
        (this.plan.objects || []).forEach((o, i) => h += box(o, 'object', i, '#B8BCCB'));
        // 구역 (윤곽 + 좌석 미리보기 + 이름)
        let seatsSvg = '';
        this.sections.forEach((s, i) => {
            const c = this.tier(s.tier).color, sel = isSel('section', i);
            const sec = s.shape?.type === 'poly' ? s : { ...s, layout: P.layoutFromCounts(s.counts) || null };
            h += `<path data-kind="section" data-i="${i}" d="${P.outline(s)}" fill="${c}" fill-opacity="${sel ? .32 : .18}" stroke="${c}" stroke-width="${sel ? 3 : 1.5}" class="pe-hit ${sel ? 'pe-sel' : ''}"/>`;
            if (this.showSeats) for (const st of P.seats(sec)) seatsSvg += `<circle cx="${st.x.toFixed(1)}" cy="${st.y.toFixed(1)}" r="${st.r.toFixed(2)}" fill="${c}"/>`;
        });
        h += `<g pointer-events="none">${seatsSvg}</g>`;
        this.sections.forEach((s) => { const p = P.center(s); h += `<text x="${p.x}" y="${p.y}" text-anchor="middle" dominant-baseline="central" font-size="22" font-weight="900" class="pe-code" pointer-events="none">${esc(s.code)}</text>` });
        // 글자
        (this.plan.labels || []).forEach((l, i) => h += `<text data-kind="label" data-i="${i}" x="${l.x}" y="${l.y}" text-anchor="middle" dominant-baseline="central" font-size="${l.size}" font-weight="800" class="pe-hit pe-label ${isSel('label', i) ? 'pe-sel' : ''}">${esc(l.text)}</text>`);
        // 손잡이 (선택한 것)
        h += this.handles();
        // 다각형 그리는 중
        if (this.polyDraft) {
            const pts = this.polyDraft;
            h += `<polyline points="${pts.map(p => p.join(',')).join(' ')}" fill="none" stroke="#FF5A5F" stroke-width="2" stroke-dasharray="6 4" pointer-events="none"/>`;
            pts.forEach(([x, y], k) => h += `<circle cx="${x}" cy="${y}" r="${k ? 5 : 8}" fill="${k ? '#FF5A5F' : '#FFD23F'}" stroke="#17132B" stroke-width="1.5" ${k ? 'pointer-events="none"' : 'data-handle="close"'} class="pe-handle"/>`);
        }
        this.$refs.canvas.innerHTML = h;
    },
    handles() {
        if (!this.sel || this.sel.kind === 'label') return '';
        const dot = (x, y, name, title) => `<circle cx="${x}" cy="${y}" r="7" data-handle="${name}" class="pe-handle"><title>${title}</title></circle>`;
        const o = this.selObj(); if (!o) return '';
        const rotPt = (x, y, b) => { const r = (Number(b.rot) || 0) * Math.PI / 180, cx = b.x + b.w / 2, cy = b.y + b.h / 2, dx = x - cx, dy = y - cy; return [cx + dx * Math.cos(r) - dy * Math.sin(r), cy + dx * Math.sin(r) + dy * Math.cos(r)] };
        const sh = this.sel.kind === 'section' ? o.shape : o;
        if (sh.type === 'arc') {
            const a = (deg) => deg * Math.PI / 180, p = (r, d) => [sh.cx + r * Math.cos(a(d)), sh.cy + r * Math.sin(a(d))];
            return dot(sh.cx, sh.cy, 'move', '중심 옮기기') + dot(...p(sh.r1, (sh.a0 + sh.a1) / 2), 'r1', '바깥 반지름') + dot(...p(sh.r0, (sh.a0 + sh.a1) / 2), 'r0', '안쪽 반지름')
                + dot(...p(sh.r1, sh.a0), 'a0', '시작 각도') + dot(...p(sh.r1, sh.a1), 'a1', '끝 각도');
        }
        if (sh.type === 'poly') return sh.points.map(([x, y], k) => dot(x, y, 'v' + k, (k + 1) + '번째 꼭짓점')).join('');
        // 사각 (구역·무대·물체): 크기(오른쪽 아래) · 회전(위)
        return dot(...rotPt(sh.x + sh.w, sh.y + sh.h, sh), 'size', '크기') + dot(...rotPt(sh.x + sh.w / 2, sh.y - 28, sh), 'rot', '회전');
    },

    // ── 선택 · 추가 · 삭제 ──
    selObj() {
        if (!this.sel) return null;
        const { kind, i } = this.sel;
        return kind === 'stage' ? this.plan.stage : kind === 'object' ? this.plan.objects[i] : kind === 'label' ? this.plan.labels[i] : this.sections[i];
    },
    get selSection() { return this.sel?.kind === 'section' ? this.sections[this.sel.i] : null },
    nextCode() { let n = this.sections.length + 1; while (this.sections.some(s => s.code === String(n))) n++; return String(n) },
    center() { return [this.vb.x + this.vb.w / 2, this.vb.y + this.vb.w / 2] },
    addSection(type) {
        const [cx, cy] = this.center(), base = { code: this.nextCode(), tier: this.tiers[0]?.key, rows: 10, per: 20, counts: '' };
        const common = { align: 'center', rowLabels: 'num' };
        if (type === 'arc') base.shape = { type: 'arc', cx, cy: cy + 150, r0: 180, r1: 260, a0: 200, a1: 250, row1: 'inner', dir: 'cw', ...common };
        else base.shape = { type: 'rect', x: cx - 90, y: cy - 50, w: 180, h: 100, rot: 0, curve: 0, row1: 'top', dir: 'ltr', ...common };
        this.sections.push(base); this.sel = { kind: 'section', i: this.sections.length - 1 }; this.dirty = true;
    },
    startPoly() { this.polyDraft = []; this.sel = null; this.msg = '캔버스를 눌러 꼭짓점을 찍고, 첫 점(노란 점)을 다시 누르거나 Enter 로 마쳐요.' },
    finishPoly() {
        const pts = this.polyDraft; this.polyDraft = null; this.msg = '';
        if (!pts || pts.length < 3) return;
        this.sections.push({ code: this.nextCode(), tier: this.tiers[0]?.key, rows: 0, per: 0, counts: '',
            shape: { type: 'poly', points: pts, rot: 0, gr: 10, gc: 20, row1: 'top', dir: 'ltr', align: 'center', rowLabels: 'num' } });
        this.sel = { kind: 'section', i: this.sections.length - 1 }; this.dirty = true;
    },
    addObject() { const [cx, cy] = this.center(); this.plan.objects.push({ x: cx - 100, y: cy - 30, w: 200, h: 60, rot: 0, label: 'CONSOLE' }); this.sel = { kind: 'object', i: this.plan.objects.length - 1 }; this.dirty = true },
    addLabel() { const [cx, cy] = this.center(); this.plan.labels.push({ x: cx, y: cy, text: '1F', size: 36 }); this.sel = { kind: 'label', i: this.plan.labels.length - 1 }; this.dirty = true },
    remove() {
        if (!this.sel || this.sel.kind === 'stage') return;
        const { kind, i } = this.sel, list = kind === 'section' ? this.sections : kind === 'object' ? this.plan.objects : this.plan.labels;
        list.splice(i, 1); this.sel = null; this.dirty = true;
    },
    duplicate() {
        if (!this.sel || this.sel.kind === 'stage') return;
        const { kind } = this.sel, copy = JSON.parse(JSON.stringify(this.selObj()));
        this.shift(copy, kind, 30, 30);
        if (kind === 'section') { copy.code = this.nextCode(); this.sections.push(copy); this.sel = { kind, i: this.sections.length - 1 } }
        else if (kind === 'object') { this.plan.objects.push(copy); this.sel = { kind, i: this.plan.objects.length - 1 } }
        else { this.plan.labels.push(copy); this.sel = { kind, i: this.plan.labels.length - 1 } }
        this.dirty = true;
    },
    shift(o, kind, dx, dy) {
        const sh = kind === 'section' ? o.shape : o;
        if (sh.type === 'arc') { sh.cx += dx; sh.cy += dy }
        else if (sh.type === 'poly') sh.points = sh.points.map(([x, y]) => [x + dx, y + dy]);
        else { sh.x += dx; sh.y += dy }
    },

    // ── 마우스 ──
    pt(e) { const p = this.$refs.svg.createSVGPoint(); p.x = e.clientX; p.y = e.clientY; const q = p.matrixTransform(this.$refs.svg.getScreenCTM().inverse()); return [q.x, q.y] },
    sn(v) { return this.snap ? Math.round(v / 5) * 5 : Math.round(v * 10) / 10 },
    down(e) {
        const [x, y] = this.pt(e), t = e.target;
        if (this.polyDraft) {
            if (t.dataset.handle === 'close') return this.finishPoly();
            this.polyDraft.push([this.sn(x), this.sn(y)]); return;
        }
        if (t.dataset.handle) { this.drag = { mode: t.dataset.handle, x, y, orig: JSON.parse(JSON.stringify(this.selObj())) }; return }
        if (t.dataset.kind) {
            this.sel = { kind: t.dataset.kind, i: Number(t.dataset.i) };
            this.drag = { mode: 'move', x, y, orig: JSON.parse(JSON.stringify(this.selObj())) }; return;
        }
        this.sel = null; this.drag = { mode: 'pan', cx: e.clientX, cy: e.clientY, vb: { ...this.vb } };
    },
    move(e) {
        if (!this.drag) return;
        if (this.drag.mode === 'pan') {
            const k = this.vb.w / this.$refs.svg.clientWidth;
            this.vb.x = this.drag.vb.x - (e.clientX - this.drag.cx) * k; this.vb.y = this.drag.vb.y - (e.clientY - this.drag.cy) * k; return;
        }
        const [x, y] = this.pt(e), d = this.drag, o = this.selObj(), kind = this.sel.kind, sh = kind === 'section' ? o.shape : o, os = kind === 'section' ? d.orig.shape : d.orig;
        const dx = x - d.x, dy = y - d.y;
        if (d.mode === 'move') {
            if (kind === 'label') { o.x = this.sn(os.x + dx); o.y = this.sn(os.y + dy) }
            else if (sh.type === 'arc') { sh.cx = this.sn(os.cx + dx); sh.cy = this.sn(os.cy + dy) }
            else if (sh.type === 'poly') sh.points = os.points.map(([px, py]) => [this.sn(px + dx), this.sn(py + dy)]);
            else { sh.x = this.sn(os.x + dx); sh.y = this.sn(os.y + dy) }
        } else if (d.mode === 'size') {
            const r = -(Number(os.rot) || 0) * Math.PI / 180, lx = dx * Math.cos(r) - dy * Math.sin(r), ly = dx * Math.sin(r) + dy * Math.cos(r);
            sh.w = Math.max(10, this.sn(os.w + lx)); sh.h = Math.max(10, this.sn(os.h + ly));
        } else if (d.mode === 'rot') {
            const cx = os.x + os.w / 2, cy = os.y + os.h / 2;
            let deg = Math.atan2(y - cy, x - cx) * 180 / Math.PI + 90; if (deg > 180) deg -= 360;
            sh.rot = this.snap ? Math.round(deg / 5) * 5 : Math.round(deg);
        } else if (d.mode === 'r0' || d.mode === 'r1') {
            const r = Math.round(Math.hypot(x - sh.cx, y - sh.cy));
            if (d.mode === 'r1') sh.r1 = Math.max(sh.r0 + 6, r); else sh.r0 = Math.max(0, Math.min(sh.r1 - 6, r));
        } else if (d.mode === 'a0' || d.mode === 'a1') {
            let deg = Math.atan2(y - sh.cy, x - sh.cx) * 180 / Math.PI;
            const ref = d.mode === 'a0' ? os.a0 : os.a1; while (deg - ref > 180) deg -= 360; while (ref - deg > 180) deg += 360;
            deg = this.snap ? Math.round(deg) : Math.round(deg * 10) / 10;
            if (d.mode === 'a0') sh.a0 = Math.min(deg, sh.a1 - 2); else sh.a1 = Math.max(deg, sh.a0 + 2);
        } else if (d.mode.startsWith('v')) {
            const k = Number(d.mode.slice(1)); sh.points[k] = [this.sn(x), this.sn(y)];
        }
        this.dirty = true;
    },
    up() { this.drag = null },
    wheel(e) {
        const [x, y] = this.pt(e), f = e.deltaY < 0 ? 1 / 1.15 : 1.15, w = Math.min(2400, Math.max(120, this.vb.w * f)), k = w / this.vb.w;
        this.vb = { x: x - (x - this.vb.x) * k, y: y - (y - this.vb.y) * k, w };
    },
    zoom(f) { const [cx, cy] = this.center(), w = Math.min(2400, Math.max(120, this.vb.w * f)); this.vb = { x: cx - w / 2, y: cy - w / 2, w } },
    key(e) {
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return;
        if (this.polyDraft && e.key === 'Enter') { e.preventDefault(); return this.finishPoly() }
        if (e.key === 'Escape') { this.polyDraft = null; this.sel = null; this.msg = ''; return }
        if (!this.sel) return;
        if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); return this.remove() }
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'd') { e.preventDefault(); return this.duplicate() }
        const step = e.shiftKey ? 10 : 1, mv = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[e.key];
        if (mv) { e.preventDefault(); this.shift(this.selObj(), this.sel.kind, ...mv); this.dirty = true }
    },

    // ── 합계 · 저장 ──
    seatCount(s) { return window.SeatPlan.capacity(s.shape?.type === 'poly' ? s : { ...s, layout: window.SeatPlan.layoutFromCounts(s.counts) || null }) },
    get total() { return this.sections.reduce((n, s) => n + this.seatCount(s), 0) },
    tierSeats(key) { return this.sections.filter(s => s.tier === key).reduce((n, s) => n + this.seatCount(s), 0) },
    addTier() { const k = 'T' + (this.tiers.length + 1); this.tiers.push({ key: k, name: k, color: '#F59E0B', price: 0 }); this.dirty = true },
    async save() {
        this.saving = true; this.err = ''; this.msg = '';
        try {
            const sections = this.sections.map(s => ({ code: s.code, tier: s.tier, shape: s.shape, rows: s.rows, per: s.per,
                counts: s.shape.type === 'poly' ? window.SeatPlan.polyCounts(s.shape) : s.counts }));
            const d = await window.api(@js(route('admin.seat-maps.plan.update', $map)), 'PUT', { name: this.name, tiers: this.tiers, plan: this.plan, sections });
            this.msg = d.msg + ' 좌석 ' + d.seats.toLocaleString() + '석'; this.dirty = false;
        } catch (e) { this.err = e.message }
        this.saving = false;
    },
});
</script>

<div x-data="planEditor(@js($initial))" @keydown.window="key($event)" class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
    @if ($usedBy)
        <p class="xl:col-span-2 rounded-2xl bg-pick/25 px-4 py-3 text-sm">이 도면으로 만든 공연이 <b>{{ $usedBy }}개</b> 있어요. 여기서 고쳐도 이미 만든 공연의 구역·좌석은 바뀌지 않고, 새로 등록하는 공연부터 적용돼요.</p>
    @endif

    {{-- ── 캔버스 ── --}}
    <section class="min-w-0 rounded-3xl bg-white p-3 ring-1 ring-ink/10">
        <div class="flex flex-wrap items-center gap-1.5 pb-3">
            <button type="button" @click="addSection('rect')" class="pe-tool">＋ 사각 구역</button>
            <button type="button" @click="addSection('arc')" class="pe-tool">＋ 부채꼴 구역</button>
            <button type="button" @click="startPoly()" class="pe-tool" :class="polyDraft && '!bg-signal !text-white'">＋ 다각형 구역</button>
            <span class="mx-1 h-5 w-px bg-ink/10"></span>
            <button type="button" @click="sel = { kind: 'stage', i: 0 }" class="pe-tool">무대</button>
            <button type="button" @click="addObject()" class="pe-tool">＋ 물체 (콘솔 등)</button>
            <button type="button" @click="addLabel()" class="pe-tool">＋ 글자 (1F 등)</button>
            <span class="mx-1 h-5 w-px bg-ink/10"></span>
            <button type="button" @click="duplicate()" :disabled="!sel || sel.kind === 'stage'" class="pe-tool">복제 <kbd>Ctrl+D</kbd></button>
            <button type="button" @click="remove()" :disabled="!sel || sel.kind === 'stage'" class="pe-tool !text-signal">삭제 <kbd>Del</kbd></button>
            <span class="ml-auto flex items-center gap-1.5">
                <label class="flex items-center gap-1 text-xs font-semibold"><input type="checkbox" x-model="snap"> 5 단위 맞춤</label>
                <label class="flex items-center gap-1 text-xs font-semibold"><input type="checkbox" x-model="showSeats"> 좌석 보기</label>
                <button type="button" @click="zoom(1 / 1.3)" class="pe-tool !px-2.5" aria-label="확대">＋</button>
                <button type="button" @click="zoom(1.3)" class="pe-tool !px-2.5" aria-label="축소">−</button>
                <button type="button" @click="vb = { x: -40, y: -40, w: 1080 }" class="pe-tool">전체</button>
            </span>
        </div>
        <template x-if="image">
            <label class="mb-2 flex items-center gap-2 text-xs font-semibold text-mist">밑그림(배치도) 진하기 <input type="range" min="0" max="1" step=".05" x-model.number="imgOpacity" class="w-40"></label>
        </template>
        <p x-show="msg" x-text="msg" class="mb-2 rounded-xl bg-paper px-3 py-2 text-sm font-semibold"></p>
        <svg x-ref="svg" class="pe-svg block aspect-square w-full touch-none select-none rounded-2xl bg-paper text-ink"
             :viewBox="vb.x + ' ' + vb.y + ' ' + vb.w + ' ' + vb.w"
             @pointerdown="down($event)" @pointermove.window="move($event)" @pointerup.window="up()" @wheel.prevent="wheel($event)">
            <g x-ref="canvas"></g>
        </svg>
        <p class="mt-2 text-xs text-mist">빈 곳을 끌면 화면 이동 · 휠로 확대 · 요소를 끌면 옮기기 · 손잡이(흰 점)로 크기·회전·반지름·각도·꼭짓점 · 방향키로 1씩(Shift 10씩) · Esc 선택 해제</p>
    </section>

    {{-- ── 속성 · 등급 · 저장 ── --}}
    <aside class="space-y-4">
        <div class="rounded-3xl bg-white p-4 ring-1 ring-ink/10">
            <label class="label">도면 이름</label>
            <input x-model="name" @input="dirty = true" class="field !py-2.5" maxlength="80">
            <div class="mt-3 flex items-center justify-between">
                <p class="text-sm">구역 <b class="num" x-text="sections.length"></b> · 좌석 <b class="num" x-text="total.toLocaleString()"></b>석</p>
                <button type="button" @click="save()" :disabled="saving" class="btn-ink !py-2.5 text-sm" x-text="saving ? '저장 중…' : (dirty ? '저장하기 •' : '저장하기')"></button>
            </div>
            <p x-show="err" x-text="err" class="mt-2 text-sm font-semibold text-signal"></p>
        </div>

        {{-- 선택한 구역 --}}
        <template x-if="selSection">
            <div class="space-y-3 rounded-3xl bg-white p-4 ring-1 ring-ink/10" @input="dirty = true" @change="dirty = true">
                <p class="text-sm font-black">구역 <span class="text-mist" x-text="{ rect: '· 사각', arc: '· 부채꼴', poly: '· 다각형' }[selSection.shape.type]"></span></p>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="label">이름</label><input x-model="selSection.code" class="field !py-2" maxlength="10"></div>
                    <div><label class="label">등급</label>
                        <select x-model="selSection.tier" class="field !py-2"><template x-for="t in tiers" :key="t.key"><option :value="t.key" x-text="t.name"></option></template></select>
                    </div>
                </div>
                <template x-if="selSection.shape.type !== 'poly'">
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="label">열 수</label><input type="number" min="1" max="200" x-model.number="selSection.rows" :disabled="!!selSection.counts" class="field num !py-2"></div>
                        <div><label class="label">열당 좌석</label><input type="number" min="1" max="300" x-model.number="selSection.per" :disabled="!!selSection.counts" class="field num !py-2"></div>
                        <div class="col-span-2"><label class="label">열마다 좌석 수 <span class="font-normal text-mist">(다르면: 1열부터 8,9,10…)</span></label><input x-model="selSection.counts" placeholder="비우면 열 수 × 열당 좌석" class="field num !py-2"></div>
                    </div>
                </template>
                <template x-if="selSection.shape.type === 'poly'">
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="label">격자 열 수</label><input type="number" min="1" max="200" x-model.number="selSection.shape.gr" class="field num !py-2"></div>
                        <div><label class="label">격자 칸 수</label><input type="number" min="1" max="300" x-model.number="selSection.shape.gc" class="field num !py-2"></div>
                        <div class="col-span-2"><label class="label">격자 방향(회전 °)</label><input type="number" min="-180" max="180" x-model.number="selSection.shape.rot" class="field num !py-2"></div>
                        <p class="col-span-2 text-xs text-mist">모양 밖 칸은 빠져요. 열마다 좌석 수는 모양에서 자동으로 정해져요.</p>
                    </div>
                </template>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="label">1열 (무대 쪽)</label>
                        <select x-model="selSection.shape.row1" class="field !py-2" x-show="selSection.shape.type === 'arc'"><option value="inner">안쪽</option><option value="outer">바깥쪽</option></select>
                        <select x-model="selSection.shape.row1" class="field !py-2" x-show="selSection.shape.type !== 'arc'"><option value="top">위</option><option value="bottom">아래</option></select>
                    </div>
                    <div><label class="label">1번 좌석</label>
                        <select x-model="selSection.shape.dir" class="field !py-2" x-show="selSection.shape.type === 'arc'"><option value="cw">시작 각도 쪽</option><option value="ccw">끝 각도 쪽</option></select>
                        <select x-model="selSection.shape.dir" class="field !py-2" x-show="selSection.shape.type !== 'arc'"><option value="ltr">왼쪽</option><option value="rtl">오른쪽</option></select>
                    </div>
                    <div><label class="label">짧은 열 정렬</label>
                        <select x-model="selSection.shape.align" class="field !py-2"><option value="center">가운데</option><option value="left">왼쪽(시작)</option><option value="right">오른쪽(끝)</option></select>
                    </div>
                    <div><label class="label">열 이름</label>
                        <select x-model="selSection.shape.rowLabels" class="field !py-2"><option value="num">1, 2, 3…</option><option value="alpha">A, B, C…</option></select>
                    </div>
                </div>
                <template x-if="selSection.shape.type === 'rect'">
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="label">회전 °</label><input type="number" min="-180" max="180" x-model.number="selSection.shape.rot" class="field num !py-2"></div>
                        <div><label class="label">줄 휨 <span class="num font-normal text-mist" x-text="selSection.shape.curve"></span></label><input type="range" min="-1" max="1" step=".05" x-model.number="selSection.shape.curve" class="mt-3 w-full"></div>
                        <div><label class="label">X</label><input type="number" x-model.number="selSection.shape.x" class="field num !py-2"></div>
                        <div><label class="label">Y</label><input type="number" x-model.number="selSection.shape.y" class="field num !py-2"></div>
                        <div><label class="label">너비</label><input type="number" min="10" x-model.number="selSection.shape.w" class="field num !py-2"></div>
                        <div><label class="label">높이</label><input type="number" min="10" x-model.number="selSection.shape.h" class="field num !py-2"></div>
                    </div>
                </template>
                <template x-if="selSection.shape.type === 'arc'">
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="label">중심 X</label><input type="number" x-model.number="selSection.shape.cx" class="field num !py-2"></div>
                        <div><label class="label">중심 Y</label><input type="number" x-model.number="selSection.shape.cy" class="field num !py-2"></div>
                        <div><label class="label">안쪽 반지름</label><input type="number" min="0" x-model.number="selSection.shape.r0" class="field num !py-2"></div>
                        <div><label class="label">바깥 반지름</label><input type="number" min="1" x-model.number="selSection.shape.r1" class="field num !py-2"></div>
                        <div><label class="label">시작 각도 °</label><input type="number" x-model.number="selSection.shape.a0" class="field num !py-2"></div>
                        <div><label class="label">끝 각도 °</label><input type="number" x-model.number="selSection.shape.a1" class="field num !py-2"></div>
                        <p class="col-span-2 text-xs text-mist">각도: 0° = 오른쪽, 90° = 아래, 180° = 왼쪽, 270° = 위</p>
                    </div>
                </template>
                <p class="text-sm">좌석 <b class="num" x-text="seatCount(selSection).toLocaleString()"></b>석</p>
            </div>
        </template>

        {{-- 선택한 무대 · 물체 · 글자 --}}
        <template x-if="sel && sel.kind !== 'section'">
            <div class="grid grid-cols-2 gap-2 rounded-3xl bg-white p-4 ring-1 ring-ink/10" @input="dirty = true">
                <p class="col-span-2 text-sm font-black" x-text="{ stage: '무대', object: '물체', label: '글자' }[sel.kind]"></p>
                <template x-if="sel.kind === 'label'">
                    <div class="col-span-2 grid grid-cols-2 gap-2">
                        <div class="col-span-2"><label class="label">글자</label><input x-model="selObj().text" class="field !py-2" maxlength="20"></div>
                        <div><label class="label">크기</label><input type="number" min="8" max="120" x-model.number="selObj().size" class="field num !py-2"></div>
                    </div>
                </template>
                <template x-if="sel.kind !== 'label'">
                    <div class="col-span-2 grid grid-cols-2 gap-2">
                        <div class="col-span-2"><label class="label">이름</label><input x-model="selObj().label" class="field !py-2" maxlength="20"></div>
                        <div><label class="label">너비</label><input type="number" min="5" x-model.number="selObj().w" class="field num !py-2"></div>
                        <div><label class="label">높이</label><input type="number" min="5" x-model.number="selObj().h" class="field num !py-2"></div>
                        <div><label class="label">회전 °</label><input type="number" min="-180" max="180" x-model.number="selObj().rot" class="field num !py-2"></div>
                    </div>
                </template>
            </div>
        </template>

        {{-- 등급 --}}
        <div class="rounded-3xl bg-white p-4 ring-1 ring-ink/10" @input="dirty = true">
            <div class="flex items-center justify-between"><p class="text-sm font-black">좌석 등급</p><button type="button" @click="addTier()" class="text-xs font-semibold text-stick">＋ 등급</button></div>
            <p class="mt-1 text-xs text-mist">가격은 기본값이에요. 공연을 등록할 때 바꿀 수 있어요.</p>
            <ul class="mt-3 space-y-2">
                <template x-for="(t, k) in tiers" :key="k">
                    <li class="grid grid-cols-[28px_1fr_1fr_auto] items-center gap-1.5">
                        <input type="color" x-model="t.color" class="h-7 w-7 cursor-pointer rounded-lg border-0 bg-transparent p-0" aria-label="등급 색">
                        <input x-model="t.name" class="field !py-1.5 text-sm" maxlength="10" aria-label="등급 이름">
                        <input type="number" min="0" step="1000" x-model.number="t.price" class="field num !py-1.5 text-sm" aria-label="기본 가격">
                        <span class="num w-14 text-right text-xs text-mist" x-text="tierSeats(t.key).toLocaleString() + '석'"></span>
                    </li>
                </template>
            </ul>
        </div>

        {{-- 구역 목록 --}}
        <div class="rounded-3xl bg-white p-4 ring-1 ring-ink/10">
            <p class="text-sm font-black">구역 목록</p>
            <ul class="mt-2 max-h-72 space-y-1 overflow-auto text-sm">
                <template x-for="(s, i) in sections" :key="i">
                    <li><button type="button" @click="sel = { kind: 'section', i }" class="flex w-full items-center gap-2 rounded-xl px-2 py-1.5 text-left hover:bg-paper" :class="sel && sel.kind === 'section' && sel.i === i && 'bg-paper'">
                        <span class="h-2.5 w-2.5 rounded-full" :style="'background:' + tier(s.tier).color"></span>
                        <b class="num" x-text="s.code"></b><span class="text-xs text-mist" x-text="tier(s.tier).name"></span>
                        <span class="num ml-auto text-xs text-mist" x-text="seatCount(s).toLocaleString() + '석'"></span>
                    </button></li>
                </template>
                <li x-show="!sections.length" class="px-2 py-3 text-mist">왼쪽 위 도구로 구역을 추가하세요.</li>
            </ul>
        </div>
    </aside>
</div>

<style>
    .pe-tool { display: inline-flex; align-items: center; gap: 4px; border-radius: 999px; padding: 6px 12px; font-size: 13px; font-weight: 600; background: var(--color-paper); }
    .pe-tool:hover:not(:disabled) { background: var(--color-paper-deep); }
    .pe-tool:disabled { opacity: .35; }
    .pe-tool kbd { font: 600 10px/1 var(--font-display); color: var(--color-mist); }
    .pe-svg { cursor: grab; }
    .pe-hit { cursor: move; }
    .pe-sel { stroke: #FF5A5F !important; stroke-width: 3 !important; }
    .pe-obj-text { fill: #fff; letter-spacing: .08em; }
    .pe-code { fill: var(--color-ink); stroke: var(--color-white); stroke-width: 5; paint-order: stroke; }
    .pe-label { fill: var(--color-ink); }
    .pe-label.pe-sel { fill: #FF5A5F; stroke: none !important; }
    .pe-handle { fill: #fff; stroke: #FF5A5F; stroke-width: 2.5; cursor: pointer; }
</style>
@endsection
