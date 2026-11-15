@extends('layouts.fan')
@section('title', '픽업번호 '.$order->pickup_code)
@section('tab', 'orders')

@php($steps = [2 => '주문 접수', 3 => '만드는 중', 4 => '받아 가세요', 5 => '수령 완료'])

@section('content')
<div x-data="{
        status: {{ $order->status->value }},
        alerts: false, audio: null, perm: ('Notification' in window) ? Notification.permission : 'unsupported',
        baseTitle: document.title, titleTimer: null,
        init() {
            try { this.alerts = localStorage.getItem('order_alerts') === '1' } catch (e) {}
            document.addEventListener('pointerdown', () => this.unlock(), { once: true });
            window.addEventListener('focus', () => { clearInterval(this.titleTimer); document.title = this.baseTitle });
            setInterval(() => this.poll(), 5000);
        },
        async poll() {
            if (this.status >= 5) return;
            try {
                const s = (await api('{{ route('orders.status', $order) }}')).status;
                if (s === 4 && this.status !== 4) this.ready();
                this.status = s;
            } catch (e) {}
        },
        unlock() { if (!this.audio) try { this.audio = new (window.AudioContext || window.webkitAudioContext)() } catch (e) {} },
        // 알림 받기: 소리 준비 + 브라우저 알림 허락 받기
        async enable() {
            this.unlock(); this.alerts = true;
            try { localStorage.setItem('order_alerts', '1') } catch (e) {}
            if (this.perm === 'default') { try { this.perm = await Notification.requestPermission() } catch (e) {} }
            this.chime();
        },
        chime() {
            const ctx = this.audio; if (!ctx) return;
            if (ctx.state === 'suspended') ctx.resume();
            [[0, 880], [0.2, 1174.7], [0.4, 1568]].forEach(([at, hz]) => {
                const o = ctx.createOscillator(), g = ctx.createGain();
                o.frequency.value = hz;
                g.gain.setValueAtTime(0.0001, ctx.currentTime + at);
                g.gain.exponentialRampToValueAtTime(0.5, ctx.currentTime + at + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + at + 0.4);
                o.connect(g).connect(ctx.destination); o.start(ctx.currentTime + at); o.stop(ctx.currentTime + at + 0.45);
            });
        },
        ready() {
            try { navigator.vibrate && navigator.vibrate([300, 150, 300, 150, 300]) } catch (e) {}
            if (this.alerts) this.chime();
            if (this.perm === 'granted' && document.hidden) {
                try { new Notification(@js('주문 '.$order->pickup_code.' 준비됐어요'), { body: @js($order->store->name.'에서 번호를 보여 주고 받아 가세요.'), tag: @js('order-'.$order->order_no) }) } catch (e) {}
            }
            if (!document.hasFocus()) {
                let on = false;
                this.titleTimer = setInterval(() => { on = !on; document.title = on ? @js('✅ 받아 가세요 · '.$order->pickup_code) : this.baseTitle }, 1000);
            }
        },
     }">

    @error('order')
        <p role="alert" class="mt-3 rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal">{{ $message }}</p>
    @enderror

    <article class="stub mt-3 shadow-sm transition-colors duration-500" style="--cut: 250px"
             :class="status === 4 ? '!bg-pick' : ''">
        <div class="h-[250px] px-5 pt-5 text-center">
            <p class="eyebrow">{{ $order->store->name }} 픽업번호</p>
            <p class="num mt-4 text-[96px] font-black leading-none tracking-tight" :class="status === 4 && 'animate-blink'">{{ $order->pickup_code }}</p>
            <p class="mt-5 text-lg font-bold" aria-live="polite">
                <template x-if="status === 2"><span>주문을 받았어요</span></template>
                <template x-if="status === 3"><span>지금 만들고 있어요</span></template>
                <template x-if="status === 4"><span>다 됐어요! 매장에서 번호를 보여 주세요</span></template>
                <template x-if="status === 5"><span>맛있게 드세요</span></template>
                <template x-if="status >= 11"><span class="text-signal">주문이 취소되고 환불됐어요</span></template>
                <template x-if="status === 1"><span class="text-signal">결제가 아직 안 됐어요</span></template>
            </p>
        </div>

        <div class="px-5 pb-5 pt-6">
            <ol class="grid grid-cols-4 gap-1 text-center text-[11px] font-semibold" aria-label="주문 진행">
                @foreach ($steps as $value => $label)
                    <li>
                        <span class="block h-1.5 rounded-full transition-colors" :class="status >= {{ $value }} && status < 11 ? 'bg-ink' : 'bg-paper-deep'"></span>
                        <span class="mt-1.5 block" :class="status >= {{ $value }} && status < 11 ? 'text-ink' : 'text-mist'">{{ $label }}</span>
                    </li>
                @endforeach
            </ol>

            <ul class="mt-5 space-y-1.5 border-t border-ink/5 pt-4 text-sm">
                @foreach ($order->items as $item)
                    <li class="flex justify-between gap-3">
                        <span><span class="product-name" data-ko="{{ $item->name }}">{{ $item->name }}</span>@if ($item->option_name) · {{ $item->option_name }}@endif <span class="text-mist">× {{ $item->qty }}</span></span>
                        <span class="text-mist">{{ $item->dine_option }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($order->ticket)
                <p class="mt-3 text-xs text-mist">좌석 {{ $order->ticket->seatLabel() }}</p>
            @endif
        </div>
    </article>

    @if ($order->requires_id_check && $order->status->value < 5)
        <p class="mt-4 rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal">주류가 있어요. 받을 때 신분증을 보여 주세요.</p>
    @endif

    <a href="{{ route('display', $order->store) }}" class="btn-ghost mt-4 w-full">매장 전광판 보기</a>

    {{-- D2 준비 시작 후: 매장에 취소 요청 --}}
    @if ($order->cancel_requested_at)
        <div class="mt-4 rounded-2xl bg-white/80 px-4 py-3 text-sm ring-1 ring-ink/10">
            <p class="font-bold">취소 요청 · {{ $order->cancel_responded_at ? ($order->cancel_request_result === 'approved' ? '승인돼서 환불됐어요' : '매장이 거절했어요') : '매장 답변을 기다리고 있어요' }}</p>
            @if ($order->cancel_request_response)<p class="mt-1 text-mist">매장: {{ $order->cancel_request_response }}</p>@endif
        </div>
    @elseif (in_array($order->status->value, [3, 4], true))
        <details class="mt-4 rounded-2xl bg-white/70 px-4 py-3 text-sm ring-1 ring-ink/10">
            <summary class="cursor-pointer font-semibold">매장에 취소 요청하기</summary>
            <form method="POST" action="{{ route('orders.cancel-request', $order) }}" class="mt-2">
                @csrf
                <p class="text-xs text-mist">매장이 이미 만들기 시작해서 바로 취소할 수는 없어요. 이유를 적어 요청하면 매장이 확인해요.</p>
                <input name="reason" class="field mt-2 !py-2.5" placeholder="예: 공연 시작이 늦어져 받을 수 없어요" required aria-label="취소 요청 이유">
                <button class="btn-ink mt-2 w-full !py-2.5 text-sm">취소 요청 보내기</button>
            </form>
        </details>
    @endif

    {{-- 준비 완료 알림 받기 (소리 · 진동 · 브라우저 알림) — 문자는 번호가 있으면 자동 --}}
    <div x-show="status < 4" class="mt-4 flex items-center justify-between gap-3 rounded-2xl bg-white/70 px-4 py-3 text-sm ring-1 ring-ink/10">
        <p class="text-ink/80"><b>준비되면 알려 드릴게요.</b> <span class="text-mist">이 화면을 켜 두거나, 휴대폰 번호로 문자를 보내요.</span></p>
        <button type="button" x-show="!alerts" @click="enable()" class="btn-ink shrink-0 !px-4 !py-2 text-sm">🔔 알림 받기</button>
        <span x-show="alerts" x-cloak class="shrink-0 text-xs font-bold text-stick">🔔 켜짐</span>
    </div>

    {{-- D1 구매자 취소: 매장이 준비를 시작하기 전까지 --}}
    @if ($order->status->value === 2)
        <form method="POST" action="{{ route('orders.cancel', $order) }}" class="mt-3 text-center" x-show="status === 2"
              onsubmit="return confirm('주문을 취소할까요? {{ number_format($order->total_amount) }}원이 환불돼요.')">
            @csrf
            <button class="text-sm font-semibold text-mist underline-offset-4 hover:text-signal hover:underline">주문 취소 (매장이 준비를 시작하기 전까지)</button>
        </form>
    @endif
</div>
@endsection
