@extends('layouts.backoffice')
@section('title', '쿠폰·스탬프')
@section('eyebrow', '혜택')

@section('actions')
    <a href="{{ route('admin.coupons.create') }}" class="btn-ink !py-2.5 text-sm">운영사 쿠폰 만들기</a>
@endsection

@section('content')
    {{-- 공연 관람 스탬프 (운영사 프로그램) --}}
    <section class="mb-5 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
        <h2 class="font-bold">공연 관람 스탬프 <span class="text-xs font-normal text-mist">공연이 끝나면 티켓 1매당 1개 · 다 모으면 티켓 할인 쿠폰</span></h2>
        <form method="POST" action="{{ route('admin.stamp.save') }}" class="mt-3 flex flex-wrap items-end gap-3 text-sm">
            @csrf @method('PUT')
            <div><label for="need_count" class="label">모을 개수</label><input id="need_count" name="need_count" type="number" min="1" class="field num !w-28 !py-2" value="{{ old('need_count', $program?->need_count ?? 5) }}" required></div>
            <div><label for="reward_value" class="label">보상 (티켓 할인, 원)</label><input id="reward_value" name="reward_value" type="number" min="100" step="1000" class="field num !w-40 !py-2" value="{{ old('reward_value', $program?->rewardCoupon?->value ?? 10000) }}" required></div>
            <div><label for="reward_days" class="label">쿠폰 사용 기간 (일)</label><input id="reward_days" name="reward_days" type="number" min="1" class="field num !w-28 !py-2" value="{{ old('reward_days', $program?->rewardCoupon?->valid_days ?? 180) }}" required></div>
            <label class="flex items-center gap-2 pb-2"><input type="checkbox" name="is_active" value="1" @checked($program?->is_active ?? true)> 사용</label>
            <button class="btn-ink !py-2 text-sm">저장</button>
        </form>
        @error('reward_value')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        <p class="mt-2 text-xs text-mist">공연 관람 마일리지 적립률은 <a href="{{ route('admin.settings') }}" class="row-link">설정</a>에서 바꿔요. 매장 스탬프는 각 업체가 직접 운영해요.</p>
    </section>

    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach (['' => '전체', 'platform' => '운영사 부담', 'vendor' => '업체 부담'] as $v => $l)
            <a href="{{ request()->fullUrlWithQuery(['issuer' => $v ?: null, 'page' => null]) }}" @class(['chip !px-4 !py-2', 'bg-ink text-white' => request('issuer', '') === $v, 'bg-white ring-1 ring-ink/10' => request('issuer', '') !== $v])>{{ $l }}</a>
        @endforeach
    </div>

    <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
        <table class="w-full min-w-[900px] text-sm">
            <thead class="border-b border-ink/5 text-left text-xs text-mist">
                <tr><th class="px-5 py-3 font-semibold">쿠폰</th><th class="px-3 py-3 font-semibold">혜택</th><th class="px-3 py-3 font-semibold">쓰는 곳</th><th class="px-3 py-3 font-semibold">부담</th><th class="px-3 py-3 text-right font-semibold">발급</th><th class="px-3 py-3 text-right font-semibold">사용</th><th class="px-5 py-3 font-semibold">상태</th></tr>
            </thead>
            <tbody class="divide-y divide-ink/5">
                @forelse ($coupons as $c)
                    <tr data-href="{{ route('admin.coupons.show', $c) }}">
                        <td class="px-5 py-3"><a href="{{ route('admin.coupons.show', $c) }}" class="row-link">{{ $c->name }}</a>
                            @if ($c->code)<span class="num ml-1 text-xs text-mist">{{ $c->code }}</span>@endif
                            @if ($c->source === 'stamp')<span class="chip ml-1 bg-paper">스탬프 보상</span>@endif</td>
                        <td class="px-3 py-3">{{ $c->benefitLabel() }}</td>
                        <td class="px-3 py-3 text-xs">{{ $c->scope === 'ticket' ? '티켓 · '.($c->event?->artist ?? '모든 공연') : ($c->store?->name ?? ($c->vendor?->name ? $c->vendor->name.' 전체' : '모든 매장')) }}</td>
                        <td class="px-3 py-3 text-xs">{{ $c->issuer === 'platform' ? '운영사' : $c->vendor?->name }}</td>
                        <td class="num px-3 py-3 text-right">{{ number_format($c->issued_count) }}{{ $c->total_limit ? '/'.number_format($c->total_limit) : '' }}</td>
                        <td class="num px-3 py-3 text-right">{{ number_format($c->used_count) }}</td>
                        <td class="px-5 py-3"><span @class(['chip', 'bg-stick/10 text-stick' => $c->is_active, 'bg-ink/10' => ! $c->is_active])>{{ $c->is_active ? ($c->is_public ? '공개' : '지급용') : '중지' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-5 py-10 text-center text-mist">쿠폰이 없어요.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $coupons->links() }}</div>
@endsection
