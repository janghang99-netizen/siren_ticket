@extends('layouts.backoffice')
@section('title', '스탬프')
@section('eyebrow', $store->name)

@section('content')
    <div class="mb-5 grid gap-3 sm:grid-cols-3">
        <div class="rounded-3xl bg-ink p-5 text-paper"><p class="text-xs text-paper/60">스탬프 받은 손님</p><p class="num mt-1 text-2xl font-black">{{ number_format($members) }}<span class="font-sans text-sm">명</span></p></div>
        <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/5"><p class="text-xs text-mist">보상 쿠폰 발급</p><p class="num mt-1 text-2xl font-black">{{ number_format($rewarded) }}</p></div>
        <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/5"><p class="text-xs text-mist">보상 쿠폰 사용</p><p class="num mt-1 text-2xl font-black">{{ number_format($used) }}</p></div>
    </div>

    <form method="POST" action="{{ route('partner.stamp.save') }}" class="max-w-2xl space-y-4 rounded-3xl bg-white p-6 ring-1 ring-ink/5">
        @csrf @method('PUT')
        <p class="text-sm text-ink/80">손님이 메뉴를 받아 가면 <b>1개당 스탬프 1개</b>가 찍혀요(휴대폰 번호 기준). 다 모으면 고른 메뉴 1개 무료 쿠폰이 자동으로 나가요. 현장판매도 휴대폰 번호를 넣으면 찍혀요.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <div><label for="need_count" class="label">모을 개수</label><input id="need_count" name="need_count" type="number" min="1" class="field num" value="{{ old('need_count', $program?->need_count ?? 10) }}" required></div>
            <div><label for="min_amount" class="label">최소 주문 금액 (원)</label><input id="min_amount" name="min_amount" type="number" min="0" class="field num" value="{{ old('min_amount', $program?->min_amount ?? 0) }}"></div>
            <div>
                <label for="reward_product_id" class="label">보상 메뉴 (1개 무료)</label>
                <select id="reward_product_id" name="reward_product_id" class="field" required>
                    <option value="">고르기</option>
                    @foreach ($products as $p)
                        <option value="{{ $p->id }}" @selected(old('reward_product_id', $program?->rewardCoupon?->product_id) == $p->id)>{{ $p->name }} ({{ number_format($p->price) }}원)</option>
                    @endforeach
                </select>
                @error('reward_product_id')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <div><label for="reward_days" class="label">보상 쿠폰 사용 기간 (일)</label><input id="reward_days" name="reward_days" type="number" min="1" class="field num" value="{{ old('reward_days', $program?->rewardCoupon?->valid_days ?? 60) }}" required></div>
        </div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $program?->is_active ?? true))> 스탬프 사용</label>
        <p class="rounded-2xl bg-paper px-4 py-3 text-xs text-mist">보상 메뉴 값은 우리 업체 부담이라 그 주문의 정산에서 빠져요. 무료로 받은 1개에는 스탬프가 찍히지 않고, 환불하면 찍힌 스탬프도 회수돼요.</p>
        <button class="btn-ink">저장</button>
    </form>
@endsection
