@extends('layouts.backoffice')
@section('title', '감사 로그')
@section('eyebrow', '관리자 조치 기록')

@section('content')
    <form method="GET" class="mb-5 flex flex-wrap items-end gap-3 rounded-3xl bg-white p-4 ring-1 ring-ink/5">
        <div>
            <label for="action" class="label">조치</label>
            <select id="action" name="action" class="field !py-2.5">
                <option value="">전체</option>
                @foreach (\App\Services\Audit::LABELS as $k => $l)<option value="{{ $k }}" @selected(request('action') === $k)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="q" class="label">내용 검색</label>
            <input id="q" name="q" value="{{ request('q') }}" class="field !py-2.5" placeholder="업체명, 주문번호 등">
        </div>
        <button class="btn-ink !py-3">조회</button>
        <p class="ml-auto max-w-xs text-xs text-mist">승인·정지·판매중지·환불·정산·설정·공연취소 같은 조치를 누가, 언제, 어디서(IP) 했는지 남겨요. 분쟁이 생기면 근거로 써요.</p>
    </form>

    @if ($logs->isEmpty())
        <div class="rounded-3xl bg-white px-6 py-12 text-center ring-1 ring-ink/5"><p class="font-bold">기록이 없어요</p></div>
    @else
        <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
            <table class="w-full min-w-[820px] text-sm">
                <thead class="border-b border-ink/5 text-left text-xs text-mist">
                    <tr><th class="px-5 py-3 font-semibold">일시</th><th class="px-3 py-3 font-semibold">조치</th><th class="px-3 py-3 font-semibold">내용</th><th class="px-3 py-3 font-semibold">처리자</th><th class="px-5 py-3 font-semibold">IP</th></tr>
                </thead>
                <tbody class="divide-y divide-ink/5">
                    @foreach ($logs as $log)
                        <tr>
                            <td class="num whitespace-nowrap px-5 py-3 text-xs">{{ \Illuminate\Support\Carbon::parse($log->created_at)->format('m.d H:i:s') }}</td>
                            <td class="px-3 py-3"><span class="chip bg-ink/5">{{ \App\Services\Audit::LABELS[$log->action] ?? $log->action }}</span></td>
                            <td class="px-3 py-3">{{ $log->summary }}</td>
                            <td class="px-3 py-3 text-xs">{{ $log->user_name ?? '시스템' }}<span class="block text-mist">{{ $log->user_email }}</span></td>
                            <td class="num px-5 py-3 text-xs text-mist">{{ $log->ip }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $logs->links() }}</div>
    @endif
@endsection
