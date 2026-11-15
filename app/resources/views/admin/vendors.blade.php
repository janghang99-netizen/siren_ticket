@extends('layouts.backoffice')
@section('title', '입점업체')
@section('eyebrow', '사이렌오더')

@section('actions')
    <a href="{{ route('admin.vendors.create') }}" class="btn-ink !py-2.5 text-sm">업체 등록</a>
@endsection

@section('content')
    @if ($vendors->isEmpty())
        <div class="rounded-3xl bg-white px-6 py-12 text-center ring-1 ring-ink/5">
            <p class="text-lg font-bold">입점업체가 없어요</p>
            <p class="mt-1 text-sm text-mist">업체를 등록하고 매장을 열면 공연장에서 주문을 받을 수 있어요.</p>
            <a href="{{ route('admin.vendors.create') }}" class="btn-ink mt-5">첫 업체 등록</a>
        </div>
    @else
        <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
            <table class="w-full min-w-[760px] text-sm">
                <thead class="border-b border-ink/5 text-left text-xs text-mist">
                    <tr>
                        <th class="px-5 py-3 font-semibold">업체</th>
                        <th class="px-3 py-3 font-semibold">사업자번호 · 대표</th>
                        <th class="px-3 py-3 font-semibold">로그인 계정</th>
                        <th class="px-3 py-3 text-right font-semibold">매장</th>
                        <th class="px-3 py-3 text-right font-semibold">기본 수수료</th>
                        <th class="px-3 py-3 font-semibold">상태</th>
                        <th class="px-5 py-3"><span class="sr-only">관리</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink/5">
                    @foreach ($vendors as $v)
                        <tr>
                            <td class="px-5 py-3 font-bold">{{ $v->name }}</td>
                            <td class="px-3 py-3"><span class="num">{{ $v->business_no ?? '—' }}</span> <span class="text-mist">{{ $v->representative }}</span></td>
                            <td class="px-3 py-3 text-xs">{{ $v->users->pluck('email')->join(', ') ?: '—' }}</td>
                            <td class="num px-3 py-3 text-right">{{ $v->stores_count }}</td>
                            <td class="num px-3 py-3 text-right">{{ rtrim(rtrim($v->commission_rate, '0'), '.') }}%</td>
                            <td class="px-3 py-3"><span @class(['chip', 'bg-stick/10 text-stick' => $v->isActive(), 'bg-signal/10 text-signal' => ! $v->isActive()])>{{ $v->isActive() ? '운영 중' : '이용 정지' }}</span></td>
                            <td class="px-5 py-3">
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('admin.stores.create', ['vendor' => $v->id]) }}" class="btn-ghost !px-3 !py-2 text-xs">매장 추가</a>
                                    <a href="{{ route('admin.vendors.edit', $v) }}" class="btn-ghost !px-3 !py-2 text-xs">수정</a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
