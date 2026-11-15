@extends('layouts.backoffice')
@section('title', $customer->maskedPhone())
@section('eyebrow', '고객 · '.$customer->customer_key)

@section('content')
<div class="grid gap-5 xl:grid-cols-[1fr_340px]">
    <div class="space-y-5">
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-3xl bg-ink p-5 text-paper"><p class="text-xs text-paper/60">마일리지</p><p class="num mt-1 text-2xl font-black">{{ number_format($customer->mileage_balance) }}<span class="font-sans text-sm">원</span></p></div>
            <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/5"><p class="text-xs text-mist">쿠폰 (사용 가능)</p><p class="num mt-1 text-2xl font-black">{{ $coupons->filter->usable()->count() }}<span class="text-sm text-mist">/{{ $coupons->count() }}</span></p></div>
            <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/5"><p class="text-xs text-mist">스탬프</p>
                <p class="mt-1 text-sm">@forelse ($cards as $card){{ $card->program->store?->name ?? '공연 관람' }} <b class="num">{{ $card->count }}</b>/{{ $card->program->need_count }}@if (! $loop->last) · @endif @empty — @endforelse</p></div>
        </div>

        <section>
            <h2 class="mb-3 text-lg font-bold">혜택 원장 <span class="text-xs font-normal text-mist">마일리지 · 쿠폰 · 스탬프 모든 기록 (해시 체인)</span></h2>
            <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="border-b border-ink/5 text-left text-xs text-mist"><tr><th class="px-5 py-3 font-semibold">때</th><th class="px-3 py-3 font-semibold">종류</th><th class="px-3 py-3 font-semibold">내용</th><th class="px-3 py-3 text-right font-semibold">변동</th><th class="px-3 py-3 text-right font-semibold">잔액</th><th class="px-5 py-3 font-semibold">해시</th></tr></thead>
                    <tbody class="divide-y divide-ink/5">
                        @forelse ($ledger as $l)
                            <tr>
                                <td class="num px-5 py-3 text-xs">{{ $l->created_at->format('m.d H:i') }}</td>
                                <td class="px-3 py-3">{{ \App\Models\BenefitLedger::KINDS[$l->kind] }} {{ $l->typeLabel() }}</td>
                                <td class="px-3 py-3 text-xs">{{ $l->memo }} <span class="num text-mist">{{ $l->subject }}</span></td>
                                <td @class(['num px-3 py-3 text-right font-bold', 'text-signal' => $l->amount < 0])>{{ $l->amount > 0 ? '+' : '' }}{{ number_format($l->amount) }}</td>
                                <td class="num px-3 py-3 text-right">{{ $l->balance_after !== null ? number_format($l->balance_after) : '—' }}</td>
                                <td class="num px-5 py-3 text-[11px] text-mist" title="{{ $l->hash }}">{{ substr($l->hash, 0, 10) }}…</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-8 text-center text-mist">기록이 없어요.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $ledger->links() }}</div>
        </section>
    </div>

    <aside class="space-y-5">
        <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <h2 class="font-bold">마일리지 수동 조정</h2>
            <form method="POST" action="{{ route('admin.customers.adjust', $customer) }}" class="mt-3 space-y-2">
                @csrf
                <input name="amount" type="number" class="field num !py-2.5" placeholder="+1000 또는 -1000" value="{{ old('amount') }}" required aria-label="조정 금액">
                <input name="memo" class="field !py-2.5" placeholder="사유 (원장에 남아요)" value="{{ old('memo') }}" required aria-label="조정 사유">
                @error('amount')<p class="text-sm text-signal">{{ $message }}</p>@enderror
                @error('memo')<p class="text-sm text-signal">{{ $message }}</p>@enderror
                @error('rule')<p class="text-sm text-signal">{{ $message }}</p>@enderror
                <button class="btn-ink w-full !py-2.5 text-sm">조정</button>
            </form>
            <p class="mt-2 text-xs text-mist">조정은 감사 로그와 혜택 원장에 함께 남아요.</p>
        </section>
        <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <h2 class="font-bold">쿠폰</h2>
            <ul class="mt-3 space-y-2 text-sm">
                @forelse ($coupons as $i)
                    <li class="flex justify-between gap-2"><a href="{{ route('admin.coupons.show', $i->coupon) }}" class="row-link truncate">{{ $i->coupon->name }}</a><span class="shrink-0 text-xs text-mist">{{ $i->statusLabel() }}</span></li>
                @empty
                    <li class="text-mist">없어요.</li>
                @endforelse
            </ul>
        </section>
    </aside>
</div>
@endsection
