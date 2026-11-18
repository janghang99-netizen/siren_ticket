<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0B0A12">
    <title>입장 검표 · {{ $site['brand_name'] ?? 'SIREN' }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        html, body { background: #0B0A12; }
        .gate-flash { animation: gate-in .14s ease-out; }
        @keyframes gate-in { from { opacity: 0; transform: scale(.97) } to { opacity: 1; transform: none } }
        .gate-frame { box-shadow: 0 0 0 100vmax rgba(11, 10, 18, .55); }
        .gate-corner { position: absolute; width: 34px; height: 34px; border-color: #FFD23F; }
    </style>
</head>
{{--
  검표원 휴대폰 화면 — 카메라로 관객 QR 을 연속으로 찍는다.
   · 찍자마자 화면 전체가 초록(입장)·노랑(이미 입장)·빨강(거절)으로 바뀌고 진동·소리. 같은 코드는 4초 동안 다시 안 보낸다.
   · BarcodeDetector 가 있으면 기기 내장 인식, 없으면(iOS 등) jsQR 로 가운데 네모만 읽는다.
   · 블루투스·USB 스캐너(키보드 입력)나 직접 입력도 같은 칸으로.
   · 입장 확정은 서버 DB 에서 바로 — 블록체인 기록은 서버가 뒤에서 차례대로 보낸다.
--}}
<body class="theme-keep min-h-dvh text-white antialiased" style="font-family: 'Pretendard Variable', Pretendard, system-ui, sans-serif">
<div x-data="gateScanner(@js(['events' => $events, 'gate' => $gate, 'scanUrl' => route('gate.scan.submit'), 'statsUrl' => route('gate.stats', ['event' => '__ID__'])]))"
     x-init="init()" class="mx-auto flex min-h-dvh max-w-xl flex-col">

    {{-- 상단: 공연 · 게이트 · 현황 --}}
    <header class="sticky top-0 z-20 bg-[#0B0A12]/95 px-4 pb-3 pt-[max(12px,env(safe-area-inset-top))] backdrop-blur">
        <div class="flex items-center justify-between gap-2">
            <p class="flex items-center gap-2 text-[15px] font-black">
                <span class="h-2 w-2 rounded-full" :class="camera ? 'bg-[#3BD671] animate-pulse' : 'bg-white/30'"></span>입장 검표
            </p>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="text-[12px] font-semibold text-white/50">로그아웃</button></form>
        </div>
        <div class="mt-2 grid grid-cols-[1fr_7.5rem] gap-2">
            <label class="sr-only" for="gate-event">공연</label>
            <select id="gate-event" x-model.number="eventId" @change="saveSetup(); loadStats()"
                    class="h-11 min-w-0 rounded-xl bg-white/10 px-3 text-[14px] font-bold text-white ring-1 ring-white/10 [&>option]:text-black">
                <option value="0">공연을 고르세요</option>
                @foreach ($events as $e)
                    <option value="{{ $e['id'] }}">{{ ($e['today'] ? '오늘 · ' : '').$e['label'] }}</option>
                @endforeach
            </select>
            <label class="sr-only" for="gate-name">게이트</label>
            <input id="gate-name" x-model.trim="gate" @change="saveSetup()" maxlength="40" placeholder="게이트 이름"
                   class="h-11 min-w-0 rounded-xl bg-white/10 px-3 text-[14px] font-bold text-white placeholder:text-white/35 ring-1 ring-white/10">
        </div>
        <div class="mt-2 flex items-center justify-between rounded-xl bg-white/[.06] px-3 py-2 text-[13px]" x-show="eventId" x-cloak>
            <span class="text-white/60">전체 입장</span>
            <span><b class="num text-[18px] font-black text-[#FFD23F]" x-text="won(stats.entered)"></b>
                <span class="num text-white/50"> / <span x-text="won(stats.total)"></span>명</span>
                <span class="num ml-2 text-white/40" x-show="stats.total" x-text="Math.round(stats.entered / Math.max(1, stats.total) * 100) + '%'"></span></span>
        </div>
        <p class="mt-2 text-right text-[11px] text-white/40" x-show="mine" x-cloak>이 휴대폰 <b class="num text-white/70" x-text="mine"></b>명 입장</p>
    </header>

    {{-- 카메라 --}}
    <main class="relative flex-1 px-4">
        <div class="relative mx-auto aspect-[3/4] w-full overflow-hidden rounded-3xl bg-black ring-1 ring-white/10">
            <video x-ref="video" playsinline muted class="absolute inset-0 h-full w-full object-cover" x-show="camera"></video>
            <canvas x-ref="canvas" class="hidden"></canvas>

            {{-- 읽는 네모 --}}
            <div class="pointer-events-none absolute left-1/2 top-1/2 aspect-square w-[68%] -translate-x-1/2 -translate-y-1/2 rounded-2xl gate-frame" x-show="camera">
                <span class="gate-corner left-0 top-0 rounded-tl-2xl border-l-4 border-t-4"></span>
                <span class="gate-corner right-0 top-0 rounded-tr-2xl border-r-4 border-t-4"></span>
                <span class="gate-corner bottom-0 left-0 rounded-bl-2xl border-b-4 border-l-4"></span>
                <span class="gate-corner bottom-0 right-0 rounded-br-2xl border-b-4 border-r-4"></span>
            </div>
            <p class="absolute inset-x-0 bottom-4 text-center text-[13px] font-bold text-white/80" x-show="camera">관객 휴대폰 QR을 네모 안에 비춰 주세요</p>

            {{-- 카메라 켜기 전 --}}
            <div class="absolute inset-0 grid place-items-center p-6 text-center" x-show="!camera">
                <div>
                    <svg viewBox="0 0 24 24" class="mx-auto h-12 w-12 text-[#FFD23F]" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><rect x="7" y="7" width="10" height="10" rx="1"/></svg>
                    <button type="button" @click="startCamera()" :disabled="!eventId || starting"
                            class="mt-4 h-14 w-full rounded-2xl bg-[#FFD23F] px-8 text-[17px] font-black text-[#17132B] disabled:opacity-40">
                        <span x-text="starting ? '카메라 켜는 중…' : '카메라로 검표 시작'"></span>
                    </button>
                    <p class="mt-3 text-[13px] text-white/55" x-show="!eventId">먼저 위에서 공연을 고르세요.</p>
                    <p class="mt-3 text-[13px] font-semibold text-[#FF7A7A]" x-show="cameraError" x-text="cameraError" x-cloak></p>
                </div>
            </div>

            <div class="absolute right-3 top-3 flex gap-2" x-show="camera" x-cloak>
                <button type="button" x-show="torchable" @click="toggleTorch()" class="h-10 rounded-full bg-black/55 px-3 text-[12px] font-bold" :class="torch && 'text-[#FFD23F]'">플래시</button>
                <button type="button" @click="stopCamera()" class="h-10 rounded-full bg-black/55 px-3 text-[12px] font-bold">멈춤</button>
            </div>
        </div>

        {{-- 스캐너·직접 입력 (블루투스·USB 스캐너는 이 칸에 글자를 넣고 Enter) --}}
        <form @submit.prevent="submit(manual); manual = ''" class="mt-3 flex gap-2">
            <label for="gate-manual" class="sr-only">입장 코드 직접 입력</label>
            <input id="gate-manual" x-ref="manual" x-model="manual" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="go"
                   placeholder="스캐너 · 코드 직접 입력" class="num h-12 min-w-0 flex-1 rounded-xl bg-white/10 px-3 text-[14px] text-white placeholder:text-white/35 ring-1 ring-white/10">
            <button class="h-12 shrink-0 rounded-xl bg-white/15 px-4 text-[14px] font-bold" :disabled="!manual">확인</button>
        </form>

        {{-- 최근 --}}
        <ol class="mt-4 space-y-1.5 pb-[max(16px,env(safe-area-inset-bottom))]" aria-label="최근 검표">
            <template x-for="r in recent" :key="r.id">
                <li class="flex items-center justify-between gap-3 rounded-xl bg-white/[.05] px-3 py-2 text-[13px]">
                    <span class="flex min-w-0 items-center gap-2">
                        <span class="h-2 w-2 shrink-0 rounded-full" :class="{ 'bg-[#3BD671]': r.kind === 'ok', 'bg-[#FFC233]': r.kind === 'dup', 'bg-[#FF5A5A]': r.kind === 'bad' || r.kind === 'net' }"></span>
                        <span class="truncate font-semibold" x-text="r.title + (r.sub ? ' · ' + r.sub : '')"></span>
                    </span>
                    <span class="num shrink-0 text-white/40" x-text="r.at"></span>
                </li>
            </template>
        </ol>
    </main>

    {{-- 결과: 화면 전체 색 --}}
    <div x-show="flash" x-cloak @click="hideFlash()" role="alert" aria-live="assertive"
         class="gate-flash fixed inset-0 z-50 grid place-items-center p-8 text-center"
         :style="`background:${ {ok: '#139A4A', dup: '#C98A00', bad: '#C62828', net: '#5B2A86'}[flash?.kind] || '#000' }`">
        <div>
            <p class="text-[88px] font-black leading-none" x-text="{ok: '✓', dup: '!', bad: '✕', net: '?'}[flash?.kind]"></p>
            <p class="mt-4 text-[34px] font-black leading-tight [word-break:keep-all]" x-text="flash?.title"></p>
            <p class="mt-3 text-[20px] font-bold leading-snug text-white/90 [word-break:keep-all]" x-text="flash?.sub"></p>
            <p class="mt-8 text-[13px] text-white/60">화면을 누르면 바로 다음 관객</p>
        </div>
    </div>
</div>

<script>
function gateScanner(cfg) {
    const store = {
        get(k) { try { return localStorage.getItem(k) } catch (e) { return null } },
        set(k, v) { try { localStorage.setItem(k, v) } catch (e) {} },
    };
    return {
        events: cfg.events, eventId: 0, gate: cfg.gate || '', stats: { entered: 0, total: 0 }, mine: 0,
        camera: false, starting: false, cameraError: '', torchable: false, torch: false,
        manual: '', flash: null, recent: [], busy: false, last: { code: '', at: 0 },
        stream: null, detector: null, jsQR: null, audio: null, flashTimer: null, seq: 0,

        init() {
            const saved = Number(store.get('gate.event'));
            const today = this.events.find(e => e.today);
            this.eventId = this.events.some(e => e.id === saved) ? saved : (today?.id || (this.events.length === 1 ? this.events[0].id : 0));
            this.gate = store.get('gate.name') || this.gate;
            this.mine = Number(store.get('gate.mine.' + new Date().toDateString()) || 0);
            this.loadStats();
            setInterval(() => { if (!document.hidden) this.loadStats() }, 15000);
            if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
                this.cameraError = '이 주소에서는 카메라를 켤 수 없어요 (https 주소 필요). 블루투스 스캐너나 직접 입력으로 검표하세요.';
            }
            document.addEventListener('visibilitychange', () => { if (document.hidden && this.camera) this.stopCamera() });
        },
        saveSetup() { store.set('gate.event', String(this.eventId)); store.set('gate.name', this.gate) },

        async loadStats() {
            if (!this.eventId) return;
            try { this.stats = await api(cfg.statsUrl.replace('__ID__', this.eventId)) } catch (e) {}
        },

        async startCamera() {
            this.starting = true; this.cameraError = '';
            this.audio ??= new (window.AudioContext || window.webkitAudioContext)();   // 버튼 누를 때 만들어야 iOS 에서 소리가 난다
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } } });
                const v = this.$refs.video;
                v.srcObject = this.stream;
                await v.play();
                const track = this.stream.getVideoTracks()[0];
                this.torchable = !!track.getCapabilities?.().torch;
                if ('BarcodeDetector' in window) {
                    try { if ((await BarcodeDetector.getSupportedFormats()).includes('qr_code')) this.detector = new BarcodeDetector({ formats: ['qr_code'] }) } catch (e) {}
                }
                if (!this.detector) this.jsQR = await loadJsQR();
                this.camera = true;
                this.tick();
            } catch (e) {
                this.cameraError = e?.name === 'NotAllowedError' ? '카메라 권한을 허용해 주세요 (브라우저 주소창 옆 설정).' : '카메라를 켜지 못했어요. 다른 앱이 카메라를 쓰고 있지 않은지 확인해 주세요.';
                this.stopCamera();
            }
            this.starting = false;
        },
        stopCamera() {
            this.stream?.getTracks().forEach(t => t.stop());
            this.stream = null; this.camera = false; this.torch = false;
        },
        async toggleTorch() {
            const track = this.stream?.getVideoTracks()[0];
            try { await track.applyConstraints({ advanced: [{ torch: !this.torch }] }); this.torch = !this.torch } catch (e) {}
        },

        /** 0.1초마다 한 장씩 읽는다 (결과 화면이 떠 있거나 서버 응답 기다리는 동안은 쉰다) */
        async tick() {
            if (!this.camera) return;
            if (!this.flash && !this.busy) {
                const value = await this.read().catch(() => null);
                if (value) await this.submit(value);
            }
            setTimeout(() => this.tick(), 100);
        },
        async read() {
            const v = this.$refs.video;
            if (v.readyState < 2) return null;
            if (this.detector) return (await this.detector.detect(v))[0]?.rawValue || null;
            // 가운데 네모만 잘라 작게 읽는다 (빠르고 배터리 덜 씀)
            const side = Math.min(v.videoWidth, v.videoHeight) * 0.75, size = 480;
            const c = this.$refs.canvas;
            c.width = c.height = size;
            const ctx = c.getContext('2d', { willReadFrequently: true });
            ctx.drawImage(v, (v.videoWidth - side) / 2, (v.videoHeight - side) / 2, side, side, 0, 0, size, size);
            return this.jsQR(ctx.getImageData(0, 0, size, size).data, size, size, { inversionAttempts: 'dontInvert' })?.data || null;
        },

        async submit(raw) {
            const code = String(raw || '').trim();
            if (!code) return;
            if (code === this.last.code && Date.now() - this.last.at < 4000) return;   // 같은 화면 연속 인식
            this.last = { code, at: Date.now() };
            if (!code.startsWith('SIREN1.')) return this.show('bad', 'SIREN 티켓이 아니에요', '내 티켓 화면의 입장 QR을 열어 달라고 해 주세요');
            if (!this.eventId) return this.show('bad', '공연을 먼저 고르세요', '');
            this.busy = true;
            try {
                const res = await fetch(cfg.scanUrl, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ code, event_id: this.eventId, gate: this.gate || null }),
                });
                const r = await res.json().catch(() => ({}));
                if (r.ok) {
                    this.mine++; store.set('gate.mine.' + new Date().toDateString(), String(this.mine));
                    if (r.stats) this.stats = r.stats;
                    this.show('ok', '입장', `${r.seat} · ${r.tier}석`);
                } else if (res.status === 409) {
                    this.show('dup', '이미 입장한 티켓', r.message);
                } else if (res.status === 401 || res.status === 419) {
                    this.show('net', '다시 로그인해 주세요', '로그인이 끝났어요. 새로고침 후 다시 로그인하세요.');
                } else if (res.status === 429) {
                    this.show('net', '잠시만요', '너무 빨리 찍고 있어요. 1~2초 뒤 다시 찍어 주세요.');
                } else {
                    this.show('bad', '입장 불가', r.message || '확인할 수 없는 코드예요.');
                }
            } catch (e) {
                this.last = { code: '', at: 0 };   // 연결 문제는 같은 코드를 바로 다시 찍을 수 있게
                this.show('net', '인터넷 연결 확인', '연결이 끊겼어요. 와이파이·데이터를 확인하고 다시 찍어 주세요.');
            }
            this.busy = false;
        },

        show(kind, title, sub) {
            this.flash = { kind, title, sub };
            this.recent.unshift({ id: ++this.seq, kind, title, sub, at: new Date().toTimeString().slice(0, 8) });
            this.recent = this.recent.slice(0, 8);
            navigator.vibrate?.(kind === 'ok' ? 80 : [180, 80, 180]);
            this.beep(kind);
            clearTimeout(this.flashTimer);
            this.flashTimer = setTimeout(() => this.hideFlash(), kind === 'ok' ? 1100 : 2600);
        },
        hideFlash() {
            clearTimeout(this.flashTimer); this.flash = null;
            if (!this.camera) this.$nextTick(() => this.$refs.manual?.focus());
        },
        beep(kind) {
            const a = this.audio; if (!a) return;
            const tones = kind === 'ok' ? [[1320, 0, .12]] : [[330, 0, .16], [330, .22, .16]];
            for (const [f, start, len] of tones) {
                const o = a.createOscillator(), g = a.createGain();
                o.frequency.value = f; o.type = 'square'; g.gain.value = .08;
                o.connect(g).connect(a.destination);
                o.start(a.currentTime + start); o.stop(a.currentTime + start + len);
            }
        },
    };
}
</script>
</body>
</html>
