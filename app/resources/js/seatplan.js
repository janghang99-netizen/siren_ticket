/**
 * 공연장 도면 좌석 위치 계산 — 관리자 도면 편집기 미리보기와 팬 좌석 화면이 같이 쓴다 (보이는 그대로 팔린다).
 * 캔버스 1000 × 1000.
 *
 * 구역 { shape, rows, per, layout }
 *   shape 사각: {type:'rect', x, y, w, h, rot, curve, row1:'top'|'bottom', align:'center'|'left'|'right', dir:'ltr'|'rtl'}
 *   shape 부채꼴: {type:'arc', cx, cy, r0, r1, a0, a1, row1:'inner'|'outer', align, dir:'cw'|'ccw'}   각도: 0=오른쪽, 90=아래
 *   layout: 열마다 실제 좌석 번호 [{n, label, seats:[1,2,…]}] — 없으면 rows × per
 */
const rad = (d) => d * Math.PI / 180;

/** 열 목록: [{n, label, seats}] */
export function rowsOf(sec) {
    if (Array.isArray(sec.layout) && sec.layout.length) {
        return sec.layout.map(r => ({ n: Number(r.n), label: String(r.label ?? r.n), seats: [...r.seats].map(Number).sort((a, b) => a - b) }));
    }
    const rows = Math.max(0, Number(sec.rows) || 0), per = Math.max(0, Number(sec.per ?? sec.seats_per_row) || 0);
    return Array.from({ length: rows }, (_, i) => ({ n: i + 1, label: String(i + 1), seats: Array.from({ length: per }, (_, j) => j + 1) }));
}

function rotate(x, y, cx, cy, deg) {
    if (!deg) return [x, y];
    const a = rad(deg), c = Math.cos(a), s = Math.sin(a), dx = x - cx, dy = y - cy;
    return [cx + dx * c - dy * s, cy + dx * s + dy * c];
}

/** 사각 구역 안 점 (u: 0~w, v: 0~h) → 캔버스 좌표. curve > 0 이면 줄 양끝이 1열(무대) 쪽으로 휜다 */
function rectPoint(sh, u, v) {
    const half = sh.w / 2, t = half ? (u - half) / half : 0;
    const bend = (Number(sh.curve) || 0) * sh.h * .5 * t * t;
    const vv = v + (sh.row1 === 'bottom' ? bend : -bend);
    return rotate(sh.x + u, sh.y + vv, sh.x + sh.w / 2, sh.y + sh.h / 2, Number(sh.rot) || 0);
}

function slotStart(align, k, max) {
    return align === 'left' ? 0 : align === 'right' ? max - k : (max - k) / 2;
}

/** 열 이름: 숫자(1,2,…) 또는 글자(A,B,…,Z,AA) */
export function rowName(i, style) {
    if (style !== 'alpha') return String(i + 1);
    let s = '';
    for (let n = i + 1; n > 0; n = Math.floor((n - 1) / 26)) s = String.fromCharCode(65 + (n - 1) % 26) + s;
    return s;
}

function polyCentroid(pts) {
    return pts.reduce((a, p) => [a[0] + p[0] / pts.length, a[1] + p[1] / pts.length], [0, 0]);
}

function inside(pt, poly) {
    let c = false;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
        const [xi, yi] = poly[i], [xj, yj] = poly[j];
        if ((yi > pt[1]) !== (yj > pt[1]) && pt[0] < (xj - xi) * (pt[1] - yi) / (yj - yi) + xi) c = !c;
    }
    return c;
}

/**
 * 다각형 구역: 구역 방향(rot)대로 gr 열 × gc 칸 격자를 깔고 모양 안의 칸만 좌석으로.
 * @return 좌석이 있는 열만 [{cells:[[x,y],…]}] (1열부터), 칸 크기 cell
 */
export function polyRows(sh) {
    const pts = sh.points || [];
    if (pts.length < 3) return { rows: [], cell: 1 };
    const [cx, cy] = polyCentroid(pts), rot = Number(sh.rot) || 0;
    const local = pts.map(([x, y]) => rotate(x, y, cx, cy, -rot));
    const xs = local.map(p => p[0]), ys = local.map(p => p[1]);
    const minx = Math.min(...xs), maxx = Math.max(...xs), miny = Math.min(...ys), maxy = Math.max(...ys);
    const gr = Math.max(1, sh.gr | 0), gc = Math.max(1, sh.gc | 0), cw = (maxx - minx) / gc, ch = (maxy - miny) / gr;
    const rows = [];
    for (let i = 0; i < gr; i++) {
        const v = sh.row1 === 'bottom' ? maxy - (i + .5) * ch : miny + (i + .5) * ch, cells = [];
        for (let j = 0; j < gc; j++) {
            const u = sh.dir === 'rtl' ? maxx - (j + .5) * cw : minx + (j + .5) * cw;
            if (inside([u, v], local)) cells.push(rotate(u, v, cx, cy, rot));
        }
        if (cells.length) rows.push({ cells });
    }
    return { rows, cell: Math.min(cw, ch) };
}

/** 다각형 구역의 열별 좌석 수 글자 ("8,10,12") — 편집기가 저장할 때 보낸다 */
export function polyCounts(sh) {
    return polyRows(sh).rows.map(r => r.cells.length).join(',');
}

/** 좌석 [{k:'열-번', x, y, r, row, no}] */
export function seats(sec) {
    const sh = sec.shape || {};
    if (sh.type === 'poly') {
        const { rows: pr, cell } = polyRows(sh), dot = Math.max(.6, cell * .38), out = [];
        pr.forEach((row, i) => {
            const label = sec.layout?.[i]?.label ?? rowName(i, sh.rowLabels);
            row.cells.forEach(([x, y], j) => out.push({ k: (i + 1) + '-' + (j + 1), row: String(label), no: j + 1, x, y, r: dot }));
        });
        return out;
    }
    const rows = rowsOf(sec), R = rows.length;
    if (!R) return [];
    const max = Math.max(1, ...rows.map(r => r.seats.length)), out = [];
    if (sh.type === 'arc') {
        const depth = (sh.r1 - sh.r0) / R, span = sh.a1 - sh.a0;
        rows.forEach((row, i) => {
            const radius = sh.row1 === 'outer' ? sh.r1 - (i + .5) * depth : sh.r0 + (i + .5) * depth;
            const slot = span / max, st = slotStart(sh.align, row.seats.length, max);
            const dot = Math.max(.6, Math.min(depth, radius * Math.abs(rad(slot))) * .38);
            row.seats.forEach((no, j) => {
                let a = sh.a0 + (st + j + .5) * slot;
                if (sh.dir === 'ccw') a = sh.a1 - (st + j + .5) * slot;
                out.push({ k: row.n + '-' + no, row: row.label, no, x: sh.cx + radius * Math.cos(rad(a)), y: sh.cy + radius * Math.sin(rad(a)), r: dot });
            });
        });
        return out;
    }
    // 사각 (기본)
    const rh = sh.h / R, sw = sh.w / max, dot = Math.max(.6, Math.min(rh, sw) * .38);
    rows.forEach((row, i) => {
        const v = sh.row1 === 'bottom' ? sh.h - (i + .5) * rh : (i + .5) * rh;
        const st = slotStart(sh.align, row.seats.length, max);
        row.seats.forEach((no, j) => {
            let u = (st + j + .5) * sw;
            if (sh.dir === 'rtl') u = sh.w - u;
            const [x, y] = rectPoint(sh, u, v);
            out.push({ k: row.n + '-' + no, row: row.label, no, x, y, r: dot });
        });
    });
    return out;
}

/** 구역 윤곽 SVG path (구역 바탕·클릭 영역) */
export function outline(sec) {
    const sh = sec.shape || {};
    if (sh.type === 'poly') return 'M ' + (sh.points || []).map(([x, y]) => (+x).toFixed(1) + ' ' + (+y).toFixed(1)).join(' L ') + ' Z';
    if (sh.type === 'arc') {
        const p = (r, a) => `${(sh.cx + r * Math.cos(rad(a))).toFixed(1)} ${(sh.cy + r * Math.sin(rad(a))).toFixed(1)}`;
        const large = Math.abs(sh.a1 - sh.a0) > 180 ? 1 : 0;
        return `M ${p(sh.r1, sh.a0)} A ${sh.r1} ${sh.r1} 0 ${large} 1 ${p(sh.r1, sh.a1)} L ${p(sh.r0, sh.a1)} A ${sh.r0} ${sh.r0} 0 ${large} 0 ${p(sh.r0, sh.a0)} Z`;
    }
    const n = 12, pts = [];
    for (let i = 0; i <= n; i++) pts.push(rectPoint(sh, sh.w * i / n, 0));
    for (let i = n; i >= 0; i--) pts.push(rectPoint(sh, sh.w * i / n, sh.h));
    return 'M ' + pts.map(([x, y]) => x.toFixed(1) + ' ' + y.toFixed(1)).join(' L ') + ' Z';
}

/** 구역 이름을 놓을 자리 */
export function center(sec) {
    const sh = sec.shape || {};
    if (sh.type === 'poly') { const [x, y] = polyCentroid(sh.points || [[0, 0]]); return { x, y }; }
    if (sh.type === 'arc') {
        const a = rad((sh.a0 + sh.a1) / 2), r = (sh.r0 + sh.r1) / 2;
        return { x: sh.cx + r * Math.cos(a), y: sh.cy + r * Math.sin(a) };
    }
    const [x, y] = rectPoint(sh, sh.w / 2, sh.h / 2);
    return { x, y };
}

/** 물체(무대·콘솔) 사각형의 회전 transform */
export function boxTransform(o) {
    const rot = Number(o.rot) || 0;
    return rot ? `rotate(${rot} ${o.x + o.w / 2} ${o.y + o.h / 2})` : '';
}

/** 좌석 수 (layout 이 있으면 실제 좌석 수) */
export function capacity(sec) {
    if (sec.shape?.type === 'poly') return polyRows(sec.shape).rows.reduce((n, r) => n + r.cells.length, 0);
    return rowsOf(sec).reduce((n, r) => n + r.seats.length, 0);
}

/**
 * 열마다 좌석 수 글자 → layout. 예: "8,9,10,10,12" (1열부터). 번호는 1부터.
 * 빈 글자면 null (rows × per 격자).
 */
export function layoutFromCounts(text) {
    const counts = String(text || '').split(/[\s,]+/).map(Number).filter(n => n > 0);
    if (!counts.length) return null;
    return counts.map((c, i) => ({ n: i + 1, label: String(i + 1), seats: Array.from({ length: c }, (_, j) => j + 1) }));
}

/** 도면 미리보기 SVG 안쪽 (관리자 목록 카드 등) — 무대·물체·구역(등급 색)·이름 */
export function thumb(plan, sections, tiers) {
    const color = (k) => (tiers.find(t => t.key === k) || tiers[0] || { color: '#8A84A3' }).color;
    const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => '&#' + c.charCodeAt(0) + ';');
    const box = (o, fill) => `<rect x="${o.x}" y="${o.y}" width="${o.w}" height="${o.h}" rx="8" fill="${fill}" transform="${boxTransform(o)}"/>`
        + `<text x="${o.x + o.w / 2}" y="${o.y + o.h / 2}" text-anchor="middle" dominant-baseline="central" font-size="${Math.min(30, o.h * .45)}" font-weight="800" fill="#fff">${esc(o.label)}</text>`;
    let h = plan?.stage ? box(plan.stage, '#9AA0B8') : '';
    (plan?.objects || []).forEach(o => h += box(o, '#B8BCCB'));
    sections.forEach(s => { const c = color(s.tier), p = center(s); h += `<path d="${outline(s)}" fill="${c}" fill-opacity=".85"/><text x="${p.x}" y="${p.y}" text-anchor="middle" dominant-baseline="central" font-size="22" font-weight="900" fill="#fff">${esc(s.code)}</text>` });
    (plan?.labels || []).forEach(l => h += `<text x="${l.x}" y="${l.y}" text-anchor="middle" dominant-baseline="central" font-size="${l.size}" font-weight="800" fill="#8A84A3">${esc(l.text)}</text>`);
    return h;
}

export default { rowsOf, rowName, polyRows, polyCounts, seats, outline, center, boxTransform, capacity, layoutFromCounts, thumb };
