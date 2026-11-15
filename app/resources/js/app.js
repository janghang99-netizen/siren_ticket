import './bootstrap';
import Alpine from 'alpinejs';

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

window.Alpine = Alpine;
Alpine.start();
