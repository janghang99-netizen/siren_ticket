@extends('layouts.backoffice')
@section('title', '입점업체')
@section('eyebrow', '사이렌오더')

@section('actions')
    <a href="{{ route('partner.apply') }}" target="_blank" class="btn-ghost !py-2.5 text-sm">입점 신청 페이지</a>
    <a href="{{ route('admin.vendors.create') }}" class="btn-ink !py-2.5 text-sm">업체 직접 등록</a>
@endsection

@section('content')
    @php($pending = $vendors->where('state', 'pending')->count())
    @if ($pending)
        <p class="mb-4 rounded-2xl bg-pick px-4 py-3 text-sm font-bold">심사를 기다리는 입점 신청이 {{ $pending }}건 있어요. 서류를 확인하고 승인 또는 반려해 주세요.</p>
    @endif

    @if ($vendors->isEmpty())
        <div class="rounded-3xl bg-white px-6 py-12 text-center ring-1 ring-ink/5">
            <p class="text-lg font-bold">입점업체가 없어요</p>
            <p class="mt-1 text-sm text-mist">입점 신청을 받거나 직접 등록해 주세요.</p>
        </div>
    @else
        <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
            <table class="w-full min-w-[860px] text-sm">
                <thead class="border-b border-ink/5 text-left text-xs text-mist">
                    <tr>
                        <th class="px-5 py-3 font-semibold">업체</th>
                        <th class="px-3 py-3 font-semibold">사업자번호 · 대표</th>
                        <th class="px-3 py-3 font-semibold">로그인 계정</th>
                        <th class="px-3 py-3 text-right font-semibold">매장</th>
                        <th class="px-3 py-3 text-right font-semibold">수수료</th>
                        <th class="px-3 py-3 font-semibold">정산주기</th>
                        <th class="px-5 py-3 font-semibold">심사·상태</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink/5">
                    @foreach ($vendors as $v)
                        <tr data-href="{{ route('admin.vendors.show', $v) }}">
                            <td class="px-5 py-3"><a href="{{ route('admin.vendors.show', $v) }}" class="row-link">{{ $v->name }}</a></td>
                            <td class="px-3 py-3"><span class="num">{{ $v->business_no ?? '—' }}</span> <span class="text-mist">{{ $v->representative }}</span></td>
                            <td class="px-3 py-3 text-xs">{{ $v->users->pluck('email')->join(', ') ?: '—' }}</td>
                            <td class="num px-3 py-3 text-right">{{ $v->stores_count }}</td>
                            <td class="num px-3 py-3 text-right">{{ rtrim(rtrim($v->commission_rate, '0'), '.') }}%</td>
                            <td class="px-3 py-3 text-xs">{{ $v->cycleLabel() }}</td>
                            <td class="px-5 py-3">
                                <span @class(['chip',
                                    'bg-pick' => $v->state === 'pending',
                                    'bg-stick/10 text-stick' => $v->state === 'approved',
                                    'bg-signal/10 text-signal' => in_array($v->state, ['rejected', 'suspended'])])>{{ $v->stateLabel() }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
