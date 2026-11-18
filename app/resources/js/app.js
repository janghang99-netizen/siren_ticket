import './bootstrap';
import Alpine from 'alpinejs';
import SeatPlan from './seatplan';

// 공연장 도면 좌석 위치 계산 (관리자 도면 편집기 · 팬 좌석 화면 공용)
window.SeatPlan = SeatPlan;

/** CSRF 포함 JSON 요청. 실패 시 서버 msg 를 담은 Error 를 던진다. */
window.api = async (url, method = 'GET', body = null) => {
    const res = await fetch(url, {
        method,
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
        },
        body: body ? JSON.stringify(body) : null,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        const first = data.errors ? Object.values(data.errors)[0][0] : null;
        throw new Error(data.msg || first || '연결이 불안정해요. 잠시 후 다시 시도해 주세요.');
    }
    return data;
};

window.won = (n) => Number(n).toLocaleString('ko-KR');

/** QR 그리기 (내 티켓 입장 QR) — 쓰는 화면에서만 따로 받아 온다 */
window.qrSvg = async (text) => {
    const { default: qrcode } = await import('qrcode-generator');
    const qr = qrcode(0, 'M');
    qr.addData(text);
    qr.make();
    return qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
};

/** QR 읽기 (검표 화면, 기기에 BarcodeDetector 가 없을 때) */
window.loadJsQR = async () => (await import('jsqr')).default;

/**
 * 목록 행 클릭 → 상세. <tr data-href="..."> 에 적용.
 * 행 안의 링크·버튼·입력·폼은 원래 동작을 우선한다. Ctrl/⌘·가운데 클릭은 새 탭.
 * 키보드 사용자는 행 첫 칸의 실제 링크로 이동한다.
 */
const rowTarget = (e) => {
    if (e.target.closest('a, button, input, select, textarea, label, form, summary')) return null;
    return e.target.closest('[data-href]');
};
document.addEventListener('click', (e) => {
    const row = rowTarget(e);
    if (!row || window.getSelection()?.toString()) return;   // 글자 드래그 선택 중이면 이동 안 함
    if (e.metaKey || e.ctrlKey) window.open(row.dataset.href, '_blank');
    else window.location.href = row.dataset.href;
});
document.addEventListener('auxclick', (e) => {
    const row = rowTarget(e);
    if (row && e.button === 1) window.open(row.dataset.href, '_blank');
});

window.Alpine = Alpine;
Alpine.start();
