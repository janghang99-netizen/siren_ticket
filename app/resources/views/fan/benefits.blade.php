@extends('layouts.fan')
@section('title', '내 혜택')
@section('tab', 'benefits')

@section('content')
    <p class="eyebrow mt-2">마일리지 · 쿠폰 · 스탬프</p>
    <h1 class="mt-2 text-2xl font-black">내 혜택</h1>

    @if (! $customer)
        {{-- 휴대폰 인증: 회원가입 없이 번호 하나로 모든 혜택을 묶는다 --}}
        <section class="bg-white ring-1 ring-ink/5mt-6 rounded-3xl p-5">
            <p class="text-sm text-ink/70">티켓을 예매하거나 매장에서 주문할 때 쓴 휴대폰 번호로 혜택이 쌓여요. 쓰려면 번호를 한 번 인증해 주세요.</p>
            <form method="POST" action="{{ route('benefits.send') }}" class="mt-4 flex gap-2">
                @csrf
                <label for="phone" class="sr-only">휴대폰 번호</label>
                <input id="phone" name="phone" inputmode="tel" autocomplete="tel" value="{{ old('phone', $pendingPhone) }}" class="field num" placeholder="010-1234-5678" required>
                <button class="btn-ink shrink-0 !px-4 text-sm">{{ $pendingPhone ? '다시 받기' : '인증번호 받기' }}</button>
            </form>
            @error('phone')<p class="mt-2 text-sm font-semibold text-signal">{{ $message }}</p>@enderror

            @if ($pendingPhone)
                <form method="POST" action="{{ route('benefits.verify') }}" class="mt-3 flex gap-2">
                    @csrf
                    <input type="hidden" name="phone" value="{{ $pendingPhone }}">
                    <input type="hidden" name="return" value="{{ request('return') }}">
                    <label for="code" class="sr-only">인증번호 6자리</label>
                    <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="field num tracking-[0.3em]" placeholder="인증번호 6자리" required autofocus>
                    <button class="btn-ink shrink-0 !px-4 text-sm">확인</button>
                </form>
                @error('code')<p class="mt-2 text-sm font-semibold text-signal">{{ $message }}</p>@enderror
                @if (session('dev_code'))
                    <p class="mt-3 rounded-2xl bg-pick/30 px-3 py-2 text-xs">개발용 인증번호 <b class="num text-sm">{{ session('dev_code') }}</b> · 실서비스에서는 문자로 보내요</p>
                @endif
            @endif
        </section>
    @else
        {{-- 마일리지 --}}
        <section class="fandom-gradient mt-6 rounded-[28px] p-6 text-white shadow-xl shadow-ink/10">
            <div class="flex items-start justify-between">
                <p class="text-sm font-semibold text-white/80">{{ $customer->maskedPhone() }}</p>
                <form method="POST" action="{{ route('benefits.forget') }}">@csrf<button class="text-xs font-semibold text-white/80 underline">인증 해제</button></form>
            </div>
            <p class="mt-4 text-xs text-white/80">마일리지</p>
            <p class="num text-4xl font-black">{{ number_format($customer->mileage_balance) }}<span class="font-sans text-lg">원</span></p>
            <p class="mt-2 text-xs text-white/80">매장 주문·공연 티켓 결제에 {{ number_format((int) \App\Models\Setting::get('mileage_min_use')) }}원부터 쓸 수 있어요.</p>
        </section>

        {{-- 쿠폰 코드 --}}
        <form method="POST" action="{{ route('benefits.code') }}" class="mt-4 flex gap-2">
            @csrf
            <label for="coupon-code" class="sr-only">쿠폰 코드</label>
            <input id="coupon-code" name="code" class="field num uppercase" placeholder="쿠폰 코드 입력" required>
            <button class="btn-ghost shrink-0 !px-4 text-sm">등록</button>
        </form>
        @error('code')<p class="mt-2 text-sm font-semibold text-signal">{{ $message }}</p>@enderror

        @if ($freeCoupons->isNotEmpty())
            <h2 class="mt-8 text-lg font-bold">받을 수 있는 쿠폰</h2>
            <ul class="mt-3 space-y-2">
                @foreach ($freeCoupons as $c)
                    <li class="bg-white ring-1 ring-ink/5flex items-center justify-between gap-3 rounded-3xl px-5 py-4">
                        <div class="min-w-0">
                            <p class="font-bold">{{ $c->benefitLabel() }}</p>
                            <p class="truncate text-xs text-mist">{{ $c->name }} · {{ $c->scope === 'ticket' ? ($c->event?->name ?? '모든 공연') : ($c->store?->name ?? '모든 매장') }}{{ $c->min_amount ? ' · '.number_format($c->min_amount).'원 이상' : '' }}</p>
                        </div>
                        <form method="POST" action="{{ route('benefits.claim', $c) }}">@csrf<button class="btn-ink !px-4 !py-2 text-sm">받기</button></form>
                    </li>
                @endforeach
            </ul>
        @endif

        <h2 class="mt-8 text-lg font-bold">내 쿠폰 <span class="num text-mist">{{ $coupons->where('status', 'issued')->count() }}</span></h2>
        <ul class="mt-3 space-y-2">
            @forelse ($coupons as $i)
                <li @class(['stub bg-white ring-1 ring-ink/5relative overflow-hidden rounded-3xl px-5 py-4', 'opacity-50' => ! $i->usable()])>
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-bold">{{ $i->coupon->benefitLabel() }}</p>
                            <p class="truncate text-xs text-mist">{{ $i->coupon->name }} · {{ $i->coupon->scope === 'ticket' ? ($i->coupon->event?->name ?? '모든 공연') : ($i->coupon->store?->name ?? '모든 매장') }}</p>
                            @if ($i->expires_at)<p class="num text-[11px] text-mist">~{{ $i->expires_at->format('Y.m.d') }}</p>@endif
                        </div>
                        <span class="chip shrink-0 bg-ink/5">{{ $i->statusLabel() }}</span>
                    </div>
                </li>
            @empty
                <li class="text-sm text-mist">아직 받은 쿠폰이 없어요.</li>
            @endforelse
        </ul>

        <h2 class="mt-8 text-lg font-bold">스탬프</h2>
        <div class="mt-3 grid gap-2">
            @forelse ($cards as $card)
                @php($need = max(1, $card->program->need_count))
                <div class="bg-white ring-1 ring-ink/5rounded-3xl px-5 py-4">
                    <div class="flex items-center justify-between">
                        <p class="font-bold">{{ $card->program->store?->name ?? $card->program->name }}</p>
                        <p class="num text-sm"><b>{{ max(0, $card->count) }}</b><span class="text-mist">/{{ $need }}</span></p>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-1" aria-hidden="true">
                        @for ($n = 1; $n <= $need; $n++)
                            <span @class(['size-5 rounded-full ring-1 ring-ink/10', 'fandom-gradient' => $n <= $card->count, 'bg-white' => $n > $card->count])></span>
                        @endfor
                    </div>
                    @if ($card->program->rewardCoupon)<p class="mt-2 text-xs text-mist">다 모으면 {{ $card->program->rewardCoupon->benefitLabel() }}</p>@endif
                </div>
            @empty
                <p class="text-sm text-mist">매장에서 받으면 스탬프가 찍혀요. 공연을 보면 관람 스탬프도 쌓여요.</p>
            @endforelse
        </div>

        <h2 class="mt-8 text-lg font-bold">마일리지 내역</h2>
        <ul class="bg-white ring-1 ring-ink/5mt-3 divide-y divide-ink/5 overflow-hidden rounded-3xl text-sm">
            @forelse ($ledger as $l)
                <li class="flex items-center justify-between gap-3 px-5 py-3">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ $l->typeLabel() }}{{ $l->memo ? ' · '.$l->memo : '' }}</p>
                        <p class="num text-[11px] text-mist">{{ $l->created_at->format('m.d H:i') }}{{ $l->subject ? ' · '.$l->subject : '' }}</p>
                    </div>
                    <p @class(['num shrink-0 font-bold', 'text-signal' => $l->amount < 0])>{{ $l->amount > 0 ? '+' : '' }}{{ number_format($l->amount) }}</p>
                </li>
            @empty
                <li class="px-5 py-6 text-center text-mist">아직 내역이 없어요.</li>
            @endforelse
        </ul>
        <p class="mt-3 text-[11px] leading-relaxed text-mist">모든 적립·사용 기록은 위변조를 막는 해시 체인 원장에 남아요. 적립금은 매장에서 받거나 공연이 끝난 뒤 들어오고, 환불하면 회수돼요.</p>
    @endif
@endsection
