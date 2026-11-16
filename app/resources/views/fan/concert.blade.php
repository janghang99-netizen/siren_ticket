@extends('layouts.fan')
@section('title', $event->artist)
@section('tab', 'home')
@section('theme', $event->theme_color)

@php($days = ['일', '월', '화', '수', '목', '금', '토'])
@php($sections = $event->sections->map(fn ($s) => [
    'id' => $s->id, 'code' => $s->code, 'tier' => $s->tier, 'name' => $s->name,
    'price' => $s->price, 'left' => $s->remaining(), 'grid' => $s->hasSeatGrid(), 'color' => $s->color,
])->values())
@php($hasMap = (bool) $event->seat_map_id)

@section('header-action')
    <a href="{{ route('stores.index', $event) }}" class="text-sm font-semibold text-stick">공연장 매장</a>
@endsection

@section('content')
<script>
    // 좌석표 공연: 구역 좌석 격자 HTML (수천 석도 빠르게 — 버튼마다 Alpine 바인딩을 걸지 않고 한 번에 그린다)
    // 좌석 리스트가 있으면 열마다 실제 좌석 번호(길이가 달라 가운데 맞춤), 없으면 열 × 열당 좌석 격자
    window.seatRows = (g) => g.layout
        ? g.layout.map(r => ({ n: r.n, label: String(r.label), seats: r.seats }))
        : Array.from({ length: g.rows }, (_, i) => ({ n: i + 1, label: String(i + 1), seats: Array.from({ length: g.per }, (_, j) => j + 1) }));
    window.seatGridHtml = (g, chosen) => {
        const esc = (s) => s.replace(/[&<>"']/g, ch => '&#' + ch.charCodeAt(0) + ';');
        let h = '';
        for (const r of window.seatRows(g)) {
            const label = esc(r.label);
            h += `<div class="seat-row${g.layout ? ' is-listed' : ''}"><span class="seat-row-no">${label}열</span><span class="seat-row-seats">`;
            for (const n of r.seats) {
                const k = r.n + '-' + n, t = g.taken.has(k), c = chosen.includes(k);
                h += `<button type="button" data-seat="${k}" class="seat${t ? ' is-taken' : ''}${c ? ' is-chosen' : ''}"${t ? ' disabled' : ` aria-pressed="${c}"`} aria-label="${label}열 ${n}번${t ? ' 판매됨' : ''}">${n}</button>`;
            }
            h += '</span></div>';
        }
        return `<div class="seat-rows${g.layout ? ' is-listed' : ''}">${h}</div>`;
    };
</script>
<div x-data="{
        sections: @js($sections),
        selected: @js((int) old('section_id') ?: null),
        qty: @js((int) old('qty', 1)),
        max: {{ $event->max_per_buyer }},
        get section() { return this.sections.find(s => s.id === this.selected) },
        get limit() { return this.section ? Math.min(this.max, this.section.left) : this.max },
        pick(id) {
            this.selected = id;
            if (this.section?.grid) { this.chosen = []; this.qty = 0; this.loadSeats(); this.$nextTick(() => this.$refs.seatPanel?.scrollIntoView({ behavior: 'smooth', block: 'start' })); return }
            this.qty = Math.max(1, Math.min(this.qty, this.limit)); this.$nextTick(() => this.$refs.panel?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }))
        },
        // ── 좌석표 공연: 구역 좌석 고르기 (열 × 열당 좌석, 팔린 좌석은 회색) ──
        seatsUrl: @js(route('concerts.seats', [$event, 0])),
        grid: null, chosen: [], seatMsg: '', loadingSeats: false, mapZoom: false,
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
            this.$nextTick(() => this.drawSeats());
        },
        drawSeats() { if (this.grid && this.$refs.seats) this.$refs.seats.innerHTML = window.seatGridHtml(this.grid, this.chosen) },
        toggleSeat(e) {
            const b = e.target.closest('[data-seat]'); if (!b || b.disabled) return;
            const k = b.dataset.seat, i = this.chosen.indexOf(k);
            if (i >= 0) { this.chosen.splice(i, 1); b.classList.remove('is-chosen'); b.setAttribute('aria-pressed', 'false') }
            else if (this.chosen.length >= this.limit) { this.seatMsg = '한 번에 ' + this.limit + '석까지 고를 수 있어요.'; return }
            else { this.chosen.push(k); b.classList.add('is-chosen'); b.setAttribute('aria-pressed', 'true') }
            this.seatMsg = ''; this.qty = this.chosen.length;
        },
        seatLabel(k) { const [r, n] = k.split('-'); return ((this.grid && this.grid.labels[r]) || r) + '열 ' + n + '번' },
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
     }" x-init="selected && section?.grid && loadSeats()">

    <section class="mt-2 animate-rise">
        <p class="eyebrow">{{ $event->venue }}</p>
        <h1 class="mt-2 text-[34px] font-black leading-tight tracking-tight">{{ $event->artist }}</h1>
        <p class="mt-1 text-mist">{{ $event->name }}</p>
        <dl class="mt-5 grid grid-cols-2 gap-px overflow-hidden rounded-2xl bg-ink/10 text-sm">
            <div class="bg-white px-4 py-3">
                <dt class="text-xs text-mist">공연</dt>
                <dd class="num mt-0.5 font-bold">{{ $event->starts_at->format('m.d') }} {{ $days[$event->starts_at->dayOfWeek] }} {{ $event->starts_at->format('H:i') }}</dd>
            </div>
            <div class="bg-white px-4 py-3">
                <dt class="text-xs text-mist">입장</dt>
                <dd class="num mt-0.5 font-bold">{{ $event->doors_at?->format('H:i') ?? '—' }}</dd>
            </div>
        </dl>
    </section>

    <section class="mt-8" aria-labelledby="seat-title">
        <div class="flex items-end justify-between">
            <h2 id="seat-title" class="text-lg font-bold">구역 고르기</h2>
            <p class="text-xs text-mist">{{ $hasMap ? '배치도에서 구역을 누르세요' : '밝을수록 좌석이 많이 남았어요' }}</p>
        </div>
        <div class="mt-3 rounded-[28px] bg-white p-3 ring-1 ring-ink/5">
            @if ($hasMap)
                {{-- 기획사 배치도는 휴대폰에서 작아서 크게 보기(가로 스크롤) --}}
                <div class="overflow-x-auto rounded-2xl">
                    <div :style="mapZoom ? 'width: 240%' : ''">
                        @include('partials.seatmap', ['event' => $event, 'interactive' => true])
                    </div>
                </div>
                <button type="button" class="mt-2 w-full rounded-full bg-paper py-2 text-sm font-semibold" @click="mapZoom = !mapZoom"
                        x-text="mapZoom ? '배치도 작게 보기' : '배치도 크게 보기 (옆으로 밀어서 보기)'">배치도 크게 보기</button>
            @else
                @include('partials.bowl', ['event' => $event, 'interactive' => true])
            @endif
        </div>

        {{-- 구역 목록 — 좌석도를 쓰기 어려운 경우에도 고를 수 있게 --}}
        <ul class="mt-4 grid grid-cols-3 gap-2 text-sm">
            <template x-for="s in sections" :key="s.id">
                <li>
                    <button type="button" @click="s.left && pick(s.id)" :disabled="!s.left"
                            class="w-full rounded-xl px-2 py-2.5 text-left ring-1 transition"
                            :class="selected === s.id ? 'bg-ink text-white ring-ink' : 'bg-white ring-ink/10 hover:ring-ink/40'">
                        <span class="num block font-bold" x-text="s.code"></span>
                        <span class="block text-xs" :class="selected === s.id ? 'text-white/70' : (s.left ? 'text-mist' : 'text-signal font-semibold')"
                              x-text="s.left ? s.tier + (s.grid ? '' : '석') + ' · ' + won(s.left) + '석' : '매진'"></span>
                    </button>
                </li>
            </template>
        </ul>
    </section>

    {{-- 좌석표 공연: 고른 구역의 좌석 고르기 --}}
    <section x-ref="seatPanel" x-show="section && section.grid" x-cloak class="mt-6 scroll-mt-20 rounded-[28px] bg-white p-4 ring-1 ring-ink/5" aria-labelledby="seat-grid-title">
        <div class="flex items-end justify-between gap-2">
            <h2 id="seat-grid-title" class="text-lg font-bold"><span class="num" x-text="section?.code"></span>구역 좌석</h2>
            <p class="text-xs text-mist">1인 <span class="num" x-text="limit"></span>석까지</p>
        </div>
        <div class="mt-2 flex flex-wrap gap-3 text-[11px] text-mist">
            <span class="flex items-center gap-1"><span class="seat-key"></span>선택 가능</span>
            <span class="flex items-center gap-1"><span class="seat-key is-chosen"></span>내가 고른 좌석</span>
            <span class="flex items-center gap-1"><span class="seat-key is-taken"></span>판매됨</span>
        </div>
        <p x-show="loadingSeats" class="mt-4 text-sm text-mist">좌석을 불러오는 중…</p>
        <div x-ref="seats" @click="toggleSeat($event)" class="seat-grid mt-3 max-h-[55vh] overflow-auto rounded-2xl bg-paper p-2" role="group" aria-label="좌석"></div>
        <p x-show="seatMsg" x-text="seatMsg" role="status" class="mt-2 text-sm font-semibold text-signal"></p>
        <div x-show="chosen.length" class="mt-3 flex flex-wrap gap-1.5">
            <template x-for="k in chosen" :key="k"><span class="chip num" x-text="section?.code + ' · ' + seatLabel(k)"></span></template>
        </div>
    </section>
    <style>
        .seat-rows { display: flex; flex-direction: column; width: max-content; min-width: 100% }
        .seat-rows.is-listed { align-items: center }   /* 열마다 좌석 수가 달라 가운데로 모아 실제 객석 모양처럼 */
        .seat-row { display: flex; align-items: center; gap: 3px; margin-bottom: 3px; width: max-content }
        .seat-row-seats { display: flex; gap: 3px }
        .seat-row-no { position: sticky; left: 0; z-index: 1; width: 2.6rem; flex: none; padding-right: 4px; font: 600 10px/1 var(--font-num, inherit); color: #8A84A3; background: inherit; text-align: right }
        .seat-grid .seat-row-no { background: var(--color-paper, #F4F1FA) }
        .seat, .seat-key { width: 26px; height: 26px; flex: none; border-radius: 7px 7px 4px 4px; background: #fff; box-shadow: inset 0 0 0 1px rgba(23,19,43,.18); font: 600 9px/26px inherit; color: #8A84A3; text-align: center; transition: transform .1s }
        .seat-key { display: inline-block; width: 14px; height: 14px; border-radius: 4px }
        .seat:not(:disabled):hover { box-shadow: inset 0 0 0 2px var(--theme, #6C4BFF); color: #17132B }
        .seat:not(:disabled):active { transform: scale(.92) }
        .seat.is-chosen, .seat-key.is-chosen { background: #17132B; color: #fff; box-shadow: 0 0 0 2px var(--theme, #6C4BFF) }
        .seat.is-taken, .seat-key.is-taken { background: rgba(23,19,43,.12); box-shadow: none; color: transparent; cursor: not-allowed }
    </style>

    <form method="POST" action="{{ route('concerts.purchase', $event) }}" x-ref="panel" class="mt-8 scroll-mt-20">
        @csrf
        <input type="hidden" name="section_id" :value="selected">
        <template x-if="section && section.grid"><div><template x-for="k in chosen" :key="k"><input type="hidden" name="seats[]" :value="k"></template></div></template>

        @error('section_id')
            <p role="alert" class="mb-3 rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal">{{ $message }}</p>
        @enderror

        <div class="stub px-5 pb-5 pt-5 shadow-sm" style="--cut: 108px">
            <template x-if="section">
                <div class="flex h-[68px] items-start justify-between">
                    <div>
                        <p class="eyebrow" x-text="section.tier + (section.grid ? '' : '석')"></p>
                        <p class="num mt-1 text-3xl font-black"><span x-text="section.code"></span><span class="ml-1 font-sans text-base font-bold">구역</span></p>
                    </div>
                    <p class="num text-right text-lg font-bold"><span x-text="won(section.price)"></span><span class="font-sans text-sm">원</span></p>
                </div>
            </template>
            <template x-if="!section">
                <div class="h-[68px]">
                    <p class="eyebrow">좌석</p>
                    <p class="mt-2 text-lg font-bold text-mist">위 좌석도에서 구역을 골라 주세요</p>
                </div>
            </template>

            <div class="perforation my-5"></div>

            <div class="space-y-4">
                <div x-show="section && section.grid" class="flex items-center justify-between">
                    <span class="label !mb-0">고른 좌석</span>
                    <span class="num font-bold" x-text="chosen.length ? chosen.map(seatLabel).join(', ') : '위에서 좌석을 골라 주세요'"></span>
                </div>
                <div x-show="!(section && section.grid)" class="flex items-center justify-between">
                    <span class="label !mb-0">매수 <span class="font-normal text-mist">(1인 {{ $event->max_per_buyer }}매까지)</span></span>
                    <div class="flex items-center gap-1 rounded-full bg-paper p-1">
                        <button type="button" class="size-9 rounded-full bg-white text-lg font-bold disabled:opacity-30" @click="qty--" :disabled="qty <= 1" aria-label="한 장 빼기">−</button>
                        <input name="qty" x-model.number="qty" readonly class="num w-8 bg-transparent text-center font-bold" aria-label="매수">
                        <button type="button" class="size-9 rounded-full bg-white text-lg font-bold disabled:opacity-30" @click="qty++" :disabled="qty >= limit" aria-label="한 장 더하기">+</button>
                    </div>
                </div>
                {{-- 예매는 회원만: 예매자 = 로그인한 회원 (1인 매수 제한을 계정 단위로 지켜 리셀을 막는다) --}}
                @if ($member)
                    <div class="flex items-center justify-between rounded-2xl bg-paper px-4 py-3 text-sm">
                        <span>예매자 <b>{{ $member->name }}</b> <span class="num text-mist">{{ $member->maskedPhone() }}</span></span>
                        <a href="{{ route('member.account') }}" class="text-xs font-semibold text-stick">내 계정</a>
                    </div>
                @else
                    <div class="rounded-2xl bg-paper px-4 py-4 text-sm">
                        <p class="font-bold">티켓 예매는 회원만 할 수 있어요</p>
                        <p class="mt-1 text-xs text-mist">{{ __('해외 팬도 가입할 수 있어요 (중국은 이메일 인증)') }}</p>
                        <div class="mt-3 flex gap-2">
                            <a href="{{ route('member.login', ['return' => request()->getRequestUri()]) }}" class="btn-ink !py-2.5 text-sm">로그인</a>
                            <a href="{{ route('member.signup') }}" class="btn-ghost !py-2.5 text-sm">회원가입</a>
                        </div>
                    </div>
                @endif

                {{-- 혜택: 인증한 번호로 예매할 때만 쿠폰·마일리지 사용 --}}
                <div class="rounded-2xl bg-paper p-4 text-sm">
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
            </div>

            <div class="mt-5 space-y-2 rounded-2xl bg-paper p-4 text-xs">
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
                <a href="{{ route('member.login', ['return' => request()->getRequestUri()]) }}" class="btn-ink mt-6 w-full text-base">로그인하고 예매하기</a>
            @elseif ($event->salesOpen())
                <button class="btn-ink mt-6 w-full text-base" :disabled="!section || (section.grid && !chosen.length)">
                    <span x-text="!section ? '구역을 골라 주세요' : (section.grid && !chosen.length ? '좌석을 골라 주세요' : won(total - discount - mileageUse) + '원 결제하기')">결제하기</span>
                </button>
            @else
                <button class="btn-ink mt-6 w-full text-base" disabled>지금은 예매 기간이 아니에요</button>
            @endif
        </div>
    </form>
</div>
@endsection
