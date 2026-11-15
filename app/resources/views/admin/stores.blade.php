@extends('layouts.backoffice')
@section('title', '매장')
@section('eyebrow', '사이렌오더')

@section('actions')
    <a href="{{ route('admin.stores.create') }}" class="btn-ink !py-2.5 text-sm">매장 열기</a>
@endsection

@section('content')
    @if ($stores->isEmpty())
        <div class="rounded-3xl bg-white px-6 py-12 text-center ring-1 ring-ink/5">
            <p class="text-lg font-bold">열린 매장이 없어요</p>
            <p class="mt-1 text-sm text-mist">입점업체를 먼저 등록한 뒤 매장을 열어 주세요.</p>
        </div>
    @else
        <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
            <table class="w-full min-w-[720px] text-sm">
                <thead class="border-b border-ink/5 text-left text-xs text-mist">
                    <tr>
                        <th class="px-5 py-3 font-semibold">매장</th>
                        <th class="px-3 py-3 font-semibold">운영 업체</th>
                        <th class="px-3 py-3 font-semibold">코드</th>
                        <th class="px-3 py-3 text-right font-semibold">상품</th>
                        <th class="px-3 py-3 text-right font-semibold">수수료</th>
                        <th class="px-3 py-3 font-semibold">상태</th>
                        <th class="px-5 py-3"><span class="sr-only">관리</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink/5">
                    @foreach ($stores as $s)
                        <tr>
                            <td class="px-5 py-3 font-bold">{{ $s->name }}</td>
                            <td class="px-3 py-3">{{ $s->vendor?->name ?? '미지정' }}</td>
                            <td class="num px-3 py-3 text-xs">{{ $s->code }}</td>
                            <td class="num px-3 py-3 text-right">{{ $s->products_count }}</td>
                            <td class="num px-3 py-3 text-right">{{ rtrim(rtrim($s->commission_rate, '0'), '.') }}%</td>
                            <td class="px-3 py-3"><span @class(['chip', 'bg-stick/10 text-stick' => $s->is_open, 'bg-ink/10' => ! $s->is_open])>{{ $s->is_open ? '영업 중' : '영업 종료' }}</span></td>
                            <td class="px-5 py-3">
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('display', $s) }}" target="_blank" class="btn-ghost !px-3 !py-2 text-xs">전광판</a>
                                    <a href="{{ route('admin.stores.edit', $s->id) }}" class="btn-ghost !px-3 !py-2 text-xs">수정</a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
