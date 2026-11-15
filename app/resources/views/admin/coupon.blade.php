@extends('layouts.backoffice')
@section('title', $coupon->name)
@section('eyebrow', '쿠폰 · '.$coupon->funderLabel())

@section('actions')
    @if ($coupon->issuer === 'platform')<a href="{{ route('admin.coupons.edit', $coupon) }}" class="btn-ink !py-2.5 text-sm">쿠폰 수정</a>@endif
@endsection

@section('content')
<div class="grid gap-5 xl:grid-cols-[1fr_340px]">
    <div class="space-y-5">
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-3xl bg-ink p-5 text-paper"><p class="text-xs text-paper/60">혜택</p><p class="mt-1 text-xl font-black">{{ $coupon->benefitLabel() }}</p></div>
            <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/5"><p class="text-xs text-mist">발급</p><p class="num mt-1 text-2xl font-black">{{ number_format($coupon->issued_count) }}<span class="text-sm text-mist">{{ $coupon->total_limit ? '/'.number_format($coupon->total_limit) : '' }}</span></p></div>
            <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/5"><p class="text-xs text-mist">사용</p><p class="num mt-1 text-2xl font-black">{{ number_format($coupon->used_count) }}</p></div>
        </div>

        <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                @foreach ([
                    '쓰는 곳' => $coupon->scope === 'ticket' ? '공연 티켓 · '.($coupon->event?->name ?? '모든 공연') : '매장 주문 · '.($coupon->store?->name ?? ($coupon->vendor ? $coupon->vendor->name.' 전체' : '모든 매장')),
                    '최소 금액' => $coupon->min_amount ? number_format($coupon->min_amount).'원' : '없음',
                    '코드' => $coupon->code ?? '—',
                    '받는 방법' => $coupon->source === 'stamp' ? '스탬프 보상' : ($coupon->is_public ? '공개 (받기 버튼)' : '직접 지급·코드'),
                    '기간' => ($coupon->starts_at?->format('Y.m.d H:i') ?? '지금부터').' ~ '.($coupon->ends_at?->format('Y.m.d H:i') ?? '제한 없음'),
                    '받은 뒤 사용 기간' => $coupon->valid_days ? $coupon->valid_days.'일' : '—',
                ] as $k => $v)
                    <div class="flex justify-between gap-3"><dt class="text-mist">{{ $k }}</dt><dd class="text-right">{{ $v }}</dd></div>
                @endforeach
            </dl>
        </section>

        <section>
            <h2 class="mb-3 text-lg font-bold">받은 사람</h2>
            <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="border-b border-ink/5 text-left text-xs text-mist"><tr><th class="px-5 py-3 font-semibold">휴대폰</th><th class="px-3 py-3 font-semibold">받은 때</th><th class="px-3 py-3 font-semibold">기한</th><th class="px-3 py-3 font-semibold">상태</th><th class="px-5 py-3 font-semibold">사용처</th></tr></thead>
                    <tbody class="divide-y divide-ink/5">
                        @forelse ($issues as $i)
                            <tr data-href="{{ route('admin.customers.show', $i->customer) }}">
                                <td class="num px-5 py-3"><a href="{{ route('admin.customers.show', $i->customer) }}" class="row-link">{{ $i->customer->maskedPhone() }}</a></td>
                                <td class="num px-3 py-3">{{ $i->created_at->format('m.d H:i') }}</td>
                                <td class="num px-3 py-3">{{ $i->expires_at?->format('m.d') ?? '—' }}</td>
                                <td class="px-3 py-3">{{ $i->statusLabel() }}</td>
                                <td class="num px-5 py-3 text-xs">{{ $i->used_ref ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-8 text-center text-mist">아직 받은 사람이 없어요.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $issues->links() }}</div>
        </section>
    </div>

    <aside>
        <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <h2 class="font-bold">직접 지급 <span class="text-xs font-normal text-mist">CS 보상 등</span></h2>
            <form method="POST" action="{{ route('admin.coupons.grant', $coupon) }}" class="mt-3 space-y-2">
                @csrf
                <input name="phone" class="field num !py-2.5" placeholder="010-1234-5678" value="{{ old('phone') }}" required aria-label="받을 휴대폰 번호">
                <input name="memo" class="field !py-2.5" placeholder="지급 사유 (원장에 남아요)" value="{{ old('memo') }}" required aria-label="지급 사유">
                @error('phone')<p class="text-sm text-signal">{{ $message }}</p>@enderror
                @error('memo')<p class="text-sm text-signal">{{ $message }}</p>@enderror
                <button class="btn-ink w-full !py-2.5 text-sm">쿠폰 주기</button>
            </form>
        </section>
    </aside>
</div>
@endsection
