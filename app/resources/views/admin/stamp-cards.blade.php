@extends('layouts.backoffice')
@section('title', '쿠폰·스탬프')
@section('eyebrow', '회원별 스탬프')

@section('content')
    @include('admin.partials.coupon-tabs')

    <div class="mb-5 grid gap-3 sm:grid-cols-[1fr_1fr_2fr]">
        <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/5"><p class="text-xs text-mist">스탬프 카드</p><p class="num mt-1 text-2xl font-black">{{ number_format($cardCount) }}<span class="font-sans text-sm">장</span></p></div>
        <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/5"><p class="text-xs text-mist">스탬프 보유 고객</p><p class="num mt-1 text-2xl font-black">{{ number_format($customerCount) }}<span class="font-sans text-sm">명</span></p></div>
        <form method="GET" class="rounded-3xl bg-white p-5 ring-1 ring-ink/5">
            <div class="flex flex-wrap items-end gap-2">
                <div class="min-w-[10rem] flex-1"><label for="q" class="text-xs text-mist">회원 이름·휴대폰 (뒷자리도 돼요)</label><input id="q" name="q" value="{{ request('q') }}" class="field !py-2" placeholder="홍길동 / 5678"></div>
                <div><label for="store" class="text-xs text-mist">매장</label>
                    <select id="store" name="store" class="field !py-2">
                        <option value="">전체</option>
                        @if ($hasPlatform)<option value="platform" @selected(request('store') === 'platform')>공연 관람 스탬프 (운영사)</option>@endif
                        @foreach ($stores as $s)<option value="{{ $s->id }}" @selected(request('store') === (string) $s->id)>{{ $s->name }}</option>@endforeach
                    </select>
                </div>
                <button class="btn-ink !py-2 text-sm">찾기</button>
            </div>
        </form>
    </div>

    <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
        <table class="w-full min-w-[760px] text-sm">
            <thead class="border-b border-ink/5 text-left text-xs text-mist"><tr>
                <th class="px-5 py-3 font-semibold">회원</th><th class="px-3 py-3 font-semibold">휴대폰</th><th class="px-3 py-3 font-semibold">매장</th>
                <th class="px-3 py-3 text-right font-semibold">현재 / 목표</th><th class="px-3 py-3 text-right font-semibold">받은 무료쿠폰</th>
                <th class="px-3 py-3 text-right font-semibold">누적 적립</th><th class="px-5 py-3 font-semibold">최근 적립</th>
            </tr></thead>
            <tbody class="divide-y divide-ink/5">
                @forelse ($cards as $card)
                    @php
                        $customer = $card->customer;
                        $member = $customer?->member;
                        $program = $card->program;
                        $phone = $customer?->smsPhone() ? $customer->maskedPhone() : ($member?->maskedPhone() ?? '—');
                    @endphp
                    <tr data-href="{{ route('admin.customers.show', $customer) }}">
                        <td class="px-5 py-3"><a href="{{ route('admin.customers.show', $customer) }}" class="row-link">{{ $member?->maskedName() ?? '비회원' }}</a></td>
                        <td class="num px-3 py-3">{{ $phone }}</td>
                        <td class="px-3 py-3">{{ $program?->owner === 'platform' ? '공연 관람 (운영사)' : ($program?->store?->name ?? '—') }}</td>
                        <td class="num px-3 py-3 text-right"><span class="font-bold">{{ number_format($card->count) }}</span> / {{ number_format($program?->need_count ?? 0) }}</td>
                        <td class="num px-3 py-3 text-right">{{ number_format((int) $card->rewards_count) }}</td>
                        <td class="num px-3 py-3 text-right text-mist">{{ number_format($card->total_earned) }}</td>
                        <td class="num px-5 py-3 text-xs">{{ $card->last_earned_at ? \Illuminate\Support\Carbon::parse($card->last_earned_at)->format('Y.m.d H:i') : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-5 py-10 text-center text-mist">스탬프를 가진 회원이 없어요.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $cards->links() }}</div>
@endsection
