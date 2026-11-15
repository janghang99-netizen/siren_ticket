@extends('layouts.backoffice')
@section('title', '고객·마일리지')
@section('eyebrow', '휴대폰 번호 = 고객')

@section('content')
    <div class="mb-5 grid gap-3 sm:grid-cols-3">
        <div class="rounded-3xl bg-ink p-5 text-paper"><p class="text-xs text-paper/60">남은 마일리지 (운영사 부채)</p><p class="num mt-1 text-2xl font-black">{{ number_format($totalMileage) }}<span class="font-sans text-sm">원</span></p></div>
        <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/5"><p class="text-xs text-mist">고객</p><p class="num mt-1 text-2xl font-black">{{ number_format($count) }}<span class="font-sans text-sm">명</span></p></div>
        <form method="GET" class="rounded-3xl bg-white p-5 ring-1 ring-ink/5">
            <label for="q" class="text-xs text-mist">번호로 찾기 (뒷자리도 돼요)</label>
            <div class="mt-1 flex gap-2"><input id="q" name="q" value="{{ request('q') }}" inputmode="numeric" class="field num !py-2" placeholder="5678"><button class="btn-ink !py-2 text-sm">찾기</button></div>
        </form>
    </div>

    <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
        <table class="w-full min-w-[640px] text-sm">
            <thead class="border-b border-ink/5 text-left text-xs text-mist"><tr><th class="px-5 py-3 font-semibold">휴대폰</th><th class="px-3 py-3 font-semibold">고객 키 (PG user_id)</th><th class="px-3 py-3 text-right font-semibold">마일리지</th><th class="px-3 py-3 text-right font-semibold">쓸 수 있는 쿠폰</th><th class="px-5 py-3 font-semibold">인증</th></tr></thead>
            <tbody class="divide-y divide-ink/5">
                @forelse ($customers as $c)
                    <tr data-href="{{ route('admin.customers.show', $c) }}">
                        <td class="num px-5 py-3"><a href="{{ route('admin.customers.show', $c) }}" class="row-link">{{ $c->maskedPhone() }}</a></td>
                        <td class="num px-3 py-3 text-xs text-mist">{{ $c->customer_key }}</td>
                        <td class="num px-3 py-3 text-right font-bold">{{ number_format($c->mileage_balance) }}</td>
                        <td class="num px-3 py-3 text-right">{{ $c->coupons_count }}</td>
                        <td class="px-5 py-3 text-xs">{{ $c->phone_verified_at ? $c->phone_verified_at->format('m.d') : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-10 text-center text-mist">고객이 없어요.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $customers->links() }}</div>
@endsection
