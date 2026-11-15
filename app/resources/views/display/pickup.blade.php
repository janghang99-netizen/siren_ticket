<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $store->name }} 픽업 · SIREN</title>
    <link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- 매장 앞 모니터용 픽업 전광판 (원본 order_board_view.php). 5초마다 갱신. --}}
<body class="min-h-dvh overflow-hidden bg-ink text-paper"
      x-data="{
        ready: [], preparing: [], fresh: [], failed: false,
        async load() {
            try {
                const d = await api('{{ route('display.data', $store) }}');
                this.fresh = d.ready.filter(c => !this.ready.includes(c));
                this.ready = d.ready; this.preparing = d.preparing; this.failed = false;
            } catch (e) { this.failed = true }
        },
      }"
      x-init="load(); setInterval(() => load(), 5000)">
    <div class="flex h-dvh flex-col p-[3vw]">
        <header class="flex items-baseline justify-between">
            <h1 class="text-[3vw] font-black">{{ $store->name }}</h1>
            <p class="text-[1.4vw] text-paper/50">번호가 노랗게 바뀌면 받으러 오세요</p>
        </header>

        <div class="mt-[2vw] grid flex-1 grid-cols-[1.6fr_1fr] gap-[2vw]">
            <section aria-labelledby="ready-title">
                <h2 id="ready-title" class="text-[1.6vw] font-bold text-pick">받아 가세요</h2>
                <ul class="mt-[1vw] grid grid-cols-3 content-start gap-[1.2vw]" aria-live="polite">
                    <template x-for="c in ready" :key="c">
                        <li class="num rounded-[1.4vw] bg-pick py-[1.2vw] text-center text-[5.4vw] font-black leading-none text-ink"
                            :class="fresh.includes(c) && 'animate-blink'" x-text="c"></li>
                    </template>
                </ul>
                <p x-show="!ready.length" class="mt-[2vw] text-[1.6vw] text-paper/40">준비된 주문이 아직 없어요</p>
            </section>

            <section aria-labelledby="prep-title" class="rounded-[1.6vw] bg-paper/5 p-[1.6vw]">
                <h2 id="prep-title" class="text-[1.6vw] font-bold text-paper/60">만드는 중</h2>
                <ul class="mt-[1vw] grid grid-cols-3 content-start gap-[1vw]">
                    <template x-for="c in preparing" :key="c">
                        <li class="num text-center text-[2.6vw] font-bold text-paper/45" x-text="c"></li>
                    </template>
                </ul>
            </section>
        </div>

        <p x-show="failed" x-cloak class="mt-[1vw] text-[1.2vw] text-signal">서버 연결이 끊겼어요. 자동으로 다시 시도하는 중…</p>
    </div>
</body>
</html>
