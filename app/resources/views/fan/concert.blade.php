@extends('layouts.fan')
@section('title', $event->artist)
@section('tab', 'home')
@section('theme', $event->theme_color)

@php($days = ['일', '월', '화', '수', '목', '금', '토'])
@php($sections = $event->sections->map(fn ($s) => [
    'id' => $s->id, 'code' => $s->code, 'tier' => $s->tier, 'name' => $s->name,
    'price' => $s->price, 'left' => $s->remaining(),
])->values())

@section('header-action')
    <a href="{{ route('stores.index', $event) }}" class="text-sm font-semibold text-stick">공연장 매장</a>
@endsection

@section('content')
<div x-data="{
        sections: @js($sections),
        selected: @js((int) old('section_id') ?: null),
        qty: @js((int) old('qty', 1)),
        max: {{ $event->max_per_buyer }},
        get section() { return this.sections.find(s => s.id === this.selected) },
        get limit() { return this.section ? Math.min(this.max, this.section.left) : this.max },
        pick(id) { this.selected = id; this.qty = Math.min(this.qty, this.limit); this.$nextTick(() => this.$refs.panel?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })) },
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
     }">

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
            <p class="text-xs text-mist">밝을수록 좌석이 많이 남았어요</p>
        </div>
        <div class="mt-3 rounded-[28px] bg-white p-3 ring-1 ring-ink/5">
            @include('partials.bowl', ['event' => $event, 'interactive' => true])
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
                              x-text="s.left ? s.tier + '석 · ' + won(s.left) + '석' : '매진'"></span>
                    </button>
                </li>
            </template>
        </ul>
    </section>

    <form method="POST" action="{{ route('concerts.purchase', $event) }}" x-ref="panel" class="mt-8 scroll-mt-20">
        @csrf
        <input type="hidden" name="section_id" :value="selected">

        @error('section_id')
            <p role="alert" class="mb-3 rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal">{{ $message }}</p>
        @enderror

        <div class="stub px-5 pb-5 pt-5 shadow-sm" style="--cut: 108px">
            <template x-if="section">
                <div class="flex h-[68px] items-start justify-between">
                    <div>
                        <p class="eyebrow" x-text="section.tier + '석'"></p>
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
                <div class="flex items-center justify-between">
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
                <button class="btn-ink mt-6 w-full text-base" :disabled="!section">
                    <span x-text="section ? won(total - discount - mileageUse) + '원 결제하기' : '구역을 골라 주세요'">결제하기</span>
                </button>
            @else
                <button class="btn-ink mt-6 w-full text-base" disabled>지금은 예매 기간이 아니에요</button>
            @endif
        </div>
    </form>
</div>
@endsection
