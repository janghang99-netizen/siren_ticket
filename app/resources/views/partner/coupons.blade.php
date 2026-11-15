@extends('layouts.backoffice')
@section('title', '쿠폰')
@section('eyebrow', $vendor->name.' · 업체 부담')

@section('actions')
    <a href="{{ route('partner.coupons.create') }}" class="btn-ink !py-2.5 text-sm">쿠폰 만들기</a>
@endsection

@section('content')
    <p class="mb-4 rounded-2xl bg-white px-4 py-3 text-sm ring-1 ring-ink/5">우리 쿠폰의 할인액은 정산에서 빠지고, 수수료는 할인 뒤 금액에 붙어요. 운영사가 뿌리는 쿠폰은 운영사가 부담해서 정산에 영향이 없어요.</p>
    <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
        <table class="w-full min-w-[760px] text-sm">
            <thead class="border-b border-ink/5 text-left text-xs text-mist"><tr><th class="px-5 py-3 font-semibold">쿠폰</th><th class="px-3 py-3 font-semibold">혜택</th><th class="px-3 py-3 font-semibold">매장</th><th class="px-3 py-3 text-right font-semibold">받음</th><th class="px-3 py-3 text-right font-semibold">사용</th><th class="px-5 py-3 font-semibold">상태</th></tr></thead>
            <tbody class="divide-y divide-ink/5">
                @forelse ($coupons as $c)
                    <tr data-href="{{ route('partner.coupons.edit', $c) }}">
                        <td class="px-5 py-3"><a href="{{ route('partner.coupons.edit', $c) }}" class="row-link">{{ $c->name }}</a>@if ($c->code)<span class="num ml-1 text-xs text-mist">{{ $c->code }}</span>@endif</td>
                        <td class="px-3 py-3">{{ $c->benefitLabel() }}{{ $c->min_amount ? ' · '.number_format($c->min_amount).'원 이상' : '' }}</td>
                        <td class="px-3 py-3 text-xs">{{ $c->store?->name ?? '우리 모든 매장' }}</td>
                        <td class="num px-3 py-3 text-right">{{ number_format($c->issued_count) }}{{ $c->total_limit ? '/'.number_format($c->total_limit) : '' }}</td>
                        <td class="num px-3 py-3 text-right">{{ number_format($c->used_count) }}</td>
                        <td class="px-5 py-3"><span @class(['chip', 'bg-stick/10 text-stick' => $c->is_active, 'bg-ink/10' => ! $c->is_active])>{{ $c->is_active ? ($c->is_public ? '공개' : '코드') : '중지' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-10 text-center text-mist">아직 쿠폰이 없어요. 스탬프 보상 쿠폰은 <a href="{{ route('partner.stamp') }}" class="row-link">스탬프</a>에서 정해요.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
