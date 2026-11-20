@extends('layouts.backoffice')
@section('title', '판매자 정산 보고서')
@section('eyebrow', '정산')

@section('actions')
    <a href="{{ route('admin.settlements.index') }}" class="btn-ghost !py-2.5 text-sm">원샷 판매자 정산으로</a>
@endsection

@section('content')
<style>
    .sr-t { border-collapse: collapse; margin-bottom: 6px; }
    .sr-t th { background: #f3f4f6; border: 1px solid #d1d5db; padding: 7px 8px; font-weight: 600; text-align: center; font-size: 12px; }
    .sr-t td { border: 1px solid #d1d5db; padding: 6px 8px; }
    .sr-t td.n { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .sr-t tr.tot td { background: #eef2ff; font-weight: 700; }
    .sr-t tr.grand td { background: #ecfdf5; font-weight: 700; font-size: 15px; }
    #srReport h3 { font-size: 15px; font-weight: 700; margin: 22px 0 8px; padding-left: 8px; border-left: 4px solid #4f46e5; }
    .sr-sub { color: #6b7280; font-size: 12px; margin-bottom: 8px; line-height: 1.7; }
    @media print {
        body * { visibility: hidden; }
        #srReport, #srReport * { visibility: visible; }
        #srReport { position: absolute; left: 0; top: 0; width: 100%; }
        @page { margin: 12mm; }
    }
</style>

<div class="space-y-5">
    <form method="GET" class="flex flex-wrap items-end gap-3 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
        <div>
            <label for="vendor" class="label">업체</label>
            <select id="vendor" name="vendor" class="field !py-2.5">
                <option value="">업체를 고르세요</option>
                @foreach ($vendors as $v)
                    <option value="{{ $v->id }}" @selected($vendor?->id === $v->id)>{{ $v->name }}</option>
                @endforeach
            </select>
        </div>
        <div><label for="rate" class="label">이용료 (%)</label><input id="rate" name="rate" value="{{ $rate }}" class="field num !w-24 !py-2.5"></div>
        @if ($from)<input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}"><input type="hidden" name="src" value="{{ $src }}">@endif
        <button class="btn-ink !py-3">적용</button>
        <p class="w-full text-xs text-mist">정산 기준 = 페이레터 카드결제(승인 − 취소, 승인일) − 이용료 {{ \App\Services\SettlementReport::pct($rate) }}% − 부가세(이용료의 {{ \App\Services\SettlementReport::pct((float) config('siren.card_settle_vat')) }}%). 현금·계좌이체는 매장 직접 수령이라 정산에서 빠져요.</p>
    </form>

    @if (! $vendor)
        <p class="text-sm text-mist">업체를 고르고 적용을 누르세요.</p>
    @else
        @if ($from)
            <div class="rounded-3xl bg-pick/40 p-5 text-sm ring-1 ring-ink/5">
                <b>{{ $src === 'done' ? '정산 완료목록' : '원샷 정산 대기목록' }}에서 연 보고서</b> — 승인일 <b class="num">{{ $from }} ~ {{ $to }}</b>
                ({{ $src === 'done' ? '이미 정산 처리된 묶음' : '이번에 정산할 묶음' }}, 정산 화면의 정산금액과 같은 기간)
                <p class="mt-1">
                    @if ($plDone)
                        이 기간의 페이레터 내역은 이미 다 불러와 있어요. 아래 보고서를 인쇄하거나 엑셀로 내려받아 제출하세요.
                    @else
                        <b class="text-signal">페이레터 내역을 {{ $plFrom }} 부터 아직 안 불러왔어요.</b> 아래 [불러오기]를 먼저 누르세요 (날짜는 채워 두었어요).
                    @endif
                    @if ($src === 'pending')<br>제출 후 <a class="underline" href="{{ route('admin.settlements.index') }}">원샷 판매자 정산</a>에서 [정산완료]를 누르면, 완료목록의 [보고서]로 같은 보고서를 다시 볼 수 있어요.@endif
                </p>
            </div>
        @endif

        {{-- 페이레터 불러오기 (하루씩, 누적 저장) --}}
        <div class="rounded-3xl border-2 border-dashed border-ink/15 bg-white p-5 text-sm">
            <p><b>{{ $report['store'] }}</b> — 페이레터에서 카드 결제 내역을 불러와 <b>누적 저장</b>해요. 이미 있는 줄은 다시 불러와도 중복 저장되지 않아요.</p>
            <div class="mt-3 flex flex-wrap items-end gap-2">
                <div><label class="label" for="plFrom">결제일</label><input id="plFrom" type="date" value="{{ $plFrom }}" class="field num !py-2"></div>
                <div><label class="label" for="plTo">~</label><input id="plTo" type="date" value="{{ $plTo }}" class="field num !py-2"></div>
                <button type="button" id="plGo" class="btn-ink !py-2.5 text-sm" @disabled(! $clients)>불러오기</button>
                <span id="plMsg" class="text-xs text-mist"></span>
            </div>
            <p class="mt-2 text-xs leading-5 text-mist">
                연결된 가맹점:
                @forelse ($clients as $c)
                    {{ $c['client_id'] }}({{ $c['kind'] === 'terminal' ? '단말기 · '.($c['store_code'] ?: '매장 미지정') : '온라인' }})
                @empty
                    <span class="text-signal">없음 — .env 의 PAYLETTER_URL · PAYLETTER_CLIENT_ID · PAYLETTER_SEARCH_KEY 확인</span>
                @endforelse
                @php $term = collect($clients)->firstWhere('kind', 'terminal'); @endphp
                @if ($clients && (! $term || ! in_array($term['store_code'], $storeCodes, true)))
                    · <span class="text-signal">이 업체 매장의 카드단말기 가맹점이 없어 단말기 결제는 불러오지 않아요 (PAYLETTER_TERMINAL_STORE)</span>
                @endif
                <br>· 온라인 결제는 이 업체 주문번호인 것만, 단말기 결제는 그 매장 것 전부 저장해요. 부분취소는 건너뛰어요.
                <br>· 페이레터 조회에는 PG 입금일이 없어 일자별 표의 정산일은 "-"로 나와요.
            </p>
            @if ($loads->isNotEmpty())
                <table class="sr-t mt-3 w-full text-xs">
                    <tr><th>저장 일시</th><th>내용</th><th>승인일 범위</th><th>줄 수</th><th>새로 저장</th><th></th></tr>
                    @foreach ($loads as $l)
                        <tr>
                            <td class="num text-center">{{ substr($l->created_at, 0, 16) }}</td><td>{{ $l->label }}</td>
                            <td class="num text-center">{{ $l->approved_from }} ~ {{ $l->approved_to }}</td>
                            <td class="n">{{ number_format($l->rows_total) }}</td><td class="n">{{ number_format($l->rows_new) }}</td>
                            <td class="text-center"><button type="button" class="text-signal underline" data-del="{{ route('admin.settlements.pg-load.destroy', [$vendor, $l->id]) }}">삭제</button></td>
                        </tr>
                    @endforeach
                </table>
                <p class="text-xs text-mist">삭제하면 그때 <b>새로 저장됐던 줄</b>이 지워져요. 지운 기간은 다시 불러오면 복구돼요.</p>
            @endif
        </div>

        @if (! $report['rows'])
            <p class="text-sm text-mist">아직 저장된 결제 내역이 없어요. 위에서 페이레터에서 불러오기를 해 주세요.</p>
        @else
            <form method="GET" class="flex flex-wrap items-end gap-2 rounded-3xl bg-white p-4 ring-1 ring-ink/5">
                <input type="hidden" name="vendor" value="{{ $vendor->id }}"><input type="hidden" name="rate" value="{{ $rate }}">
                @if ($src)<input type="hidden" name="src" value="{{ $src }}">@endif
                <div><label class="label" for="from">승인일</label><input id="from" type="date" name="from" value="{{ $report['S'] }}" class="field num !py-2"></div>
                <div><label class="label" for="to">~</label><input id="to" type="date" name="to" value="{{ $report['E'] }}" class="field num !py-2"></div>
                <button class="btn-ink !py-2.5 text-sm">기간 적용</button>
                <a class="btn-ghost !py-2.5 text-sm" href="{{ route('admin.settlements.report', ['vendor' => $vendor->id, 'rate' => $rate]) }}">전체 기간</a>
                <button type="button" class="btn-ghost !py-2.5 text-sm" onclick="window.print()">보고서 인쇄</button>
                @if ($report['sections'])
                    <a class="btn-ghost !py-2.5 text-sm" href="{{ route('admin.settlements.report.export', ['vendor' => $vendor->id, 'rate' => $rate, 'from' => $report['S'], 'to' => $report['E'], 'src' => $src]) }}">엑셀 다운로드</a>
                @endif
                <span class="text-xs text-mist">저장된 승인일: <span class="num">{{ $report['min'] }} ~ {{ $report['max'] }}</span> ({{ number_format($report['rows']) }}줄)</span>
            </form>

            @php $s = $report['sections']; $pct = \App\Services\SettlementReport::pct($rate); @endphp
            <div id="srReport" class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
                @if (! $s)
                    <p class="text-sm text-mist">선택한 기간에 승인 내역이 없어요. 위에서 페이레터에서 불러오기를 먼저 해 주세요.</p>
                @else
                    <p class="text-lg font-black">{{ $report['store'] }} 정산 보고서</p>
                    <p class="sr-sub">승인일 {{ $report['S'] }} ~ {{ $report['E'] }} · 페이레터 PG 카드결제 기준 · 이용료 {{ $pct }}% + 부가세 {{ \App\Services\SettlementReport::pct($report['vat']) }}% · 작성일 {{ now()->toDateString() }}</p>

                    <h3>{{ $report['done'] ? '정산 송금 완료 내역' : '정산 내역' }}</h3>
                    @include('admin.partials.report-table', ['head' => ['구분', '줄 수', '금액'], 'rows' => $s['sum'][0], 'cls' => $s['sum'][1]])

                    <h3>일자별 정산 (구매일 · 결제방법 · 정산일)</h3>
                    @include('admin.partials.report-table', ['head' => ['구매일', '결제방법', '정산일 (PG 입금일)', '건수', '결제금액 (취소 반영)', "이용료 {$pct}%", '부가세', '정산금액'], 'rows' => $s['day'][0], 'cls' => $s['day'][1]])
                    <p class="sr-sub">카드 = PG 결제내역(구매일은 카드 승인일, 건수는 승인·취소 줄 수) · 현금·이체 = POS 기록(구매일은 주문일, 건수는 주문 수) — 매장이 직접 받는 돈이라 이용료·정산 대상이 아니에요. {{ $s['payLab'] }}은 카드 합계 기준이고, 일자별 금액은 날짜마다 반올림해 보여 줘 줄을 더하면 몇 원 다를 수 있어요.</p>

                    @if ($s['direct'][0])
                        <h3>매장 직접 수령 — 현금·계좌이체 (참고, {{ count($s['direct'][0]) }}건 · {{ number_format($s['direct'][1]) }}원)</h3>
                        <p class="sr-sub">매장이 직접 받는 돈이라 이용료·정산 대상이 아니에요. POS에서 고른 결제수단 기준이에요.</p>
                        @include('admin.partials.report-table', ['head' => ['주문일시', '결제수단', '금액', '주문번호'], 'rows' => $s['direct'][0], 'cls' => []])
                    @endif
                    @if ($s['wrong'][0])
                        <h3>현금·계좌이체로 주문했지만 카드로 결제된 건 ({{ count($s['wrong'][0]) }}건 · {{ number_format($s['wrong'][1]) }}원)</h3>
                        <p class="sr-sub">POS에는 현금·계좌이체로 입력됐지만 PG에 같은 금액의 카드 승인(±3일)이 있어 카드 결제로 본 건이에요. 위 정산 내역(카드)에 이미 포함돼 있고, 매장 직접 수령 목록에서는 뺐어요.</p>
                        @include('admin.partials.report-table', ['head' => ['주문일시', 'POS 결제수단', '카드 승인일', '금액', '주문번호'], 'rows' => $s['wrong'][0], 'cls' => []])
                    @endif

                    <h3>EIS 매출표와의 차이 (참고)</h3>
                    <p class="sr-sub">EIS 매출표는 주문 기록(수령완료·정산완료, <b>주문일</b> 기준, 현금·계좌이체 포함)이고, 이 보고서는 PG에 실제로 승인된 <b>카드 결제</b>(승인일 기준)예요. 그래서 두 숫자는 원래 다르며, 차이는 아래와 같아요.</p>
                    @include('admin.partials.report-table', ['head' => ['구분', '금액'], 'rows' => $s['bridge'][0], 'cls' => $s['bridge'][1]])
                    @include('admin.partials.report-table', ['head' => ['월', '주문 (EIS 기준)', '그중 현금·계좌이체', '카드 주문', 'PG 카드결제', '차이 (카드 주문 − PG)'], 'rows' => $s['month'][0], 'cls' => $s['month'][1]])
                    <p class="sr-sub">EIS 화면 값이 위 "주문 합계"보다 적다면, EIS가 아직 다 불러오지 못한 주문이 있는 거예요(EIS에서 전체기간 다시 불러오기).</p>
                    @if ($s['pgOnly'][0])
                        <h3>POS 주문 없이 카드로만 받은 결제 ({{ count($s['pgOnly'][0]) }}건 · {{ number_format($s['pgOnly'][1]) }}원)</h3>
                        <p class="sr-sub">카드단말기(또는 온라인)로 결제됐지만 같은 금액의 주문이 없는 건이에요. 정산(카드)에는 포함되고 EIS 매출에는 없어요. 단말기로 받은 결제도 POS에 주문을 찍으면 맞춰져요.</p>
                        @include('admin.partials.report-table', ['head' => ['PG 승인일', '구분', '금액', '주문번호(온라인)'], 'rows' => $s['pgOnly'][0], 'cls' => []])
                    @endif
                    @if ($s['sysOnly'][0])
                        <h3>POS에 카드로 입력했지만 카드 승인이 없는 주문 ({{ count($s['sysOnly'][0]) }}건 · {{ number_format($s['sysOnly'][1]) }}원)</h3>
                        <p class="sr-sub">POS에는 카드로 찍혔지만 같은 금액의 카드 승인을 찾지 못한 주문이에요. 단말기에서 다른 금액으로 나눠 받았거나 결제수단을 잘못 고른 경우일 수 있어요. EIS 매출에는 있고 정산(카드)에는 없어요.</p>
                        @include('admin.partials.report-table', ['head' => ['주문일시', 'POS 결제수단', '금액', '주문번호'], 'rows' => $s['sysOnly'][0], 'cls' => []])
                    @endif
                    @if ($s['split'][0])
                        <h3>한 주문을 카드 여러 번으로 나눠 결제한 건 ({{ count($s['split'][0]) }}건 · {{ number_format($s['split'][1]) }}원)</h3>
                        <p class="sr-sub">POS 주문 1건을 카드단말기에서 2~3번에 나눠 승인한 건이에요. 합계가 같아 정산·EIS 차이는 없어요.</p>
                        @include('admin.partials.report-table', ['head' => ['주문일시', 'POS 주문 금액', '카드 승인 (승인일 금액)', '주문번호'], 'rows' => $s['split'][0], 'cls' => []])
                    @endif
                @endif
            </div>

            {{-- 원샷 정산 처리 현황 (인쇄 제외, 관리자 확인용) --}}
            @php $os = $report['oneshot']; @endphp
            <div class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
                <h3 class="font-bold">원샷 정산 처리 현황 (주문일 {{ $report['S'] }} ~ {{ $report['E'] }})</h3>
                <p class="sr-sub">송금한 뒤 <a class="underline" href="{{ route('admin.settlements.index') }}">원샷 판매자 정산</a>에서 [정산완료]를 누르면 주문이 정산완료로 바뀌어요. 정산 화면의 정산금액도 이 보고서와 같은 카드결제 기준(같은 승인일 기간)이에요.</p>
                @include('admin.partials.report-table', ['head' => ['구분', '주문 수', '금액 (주문 기록)', '비고'], 'rows' => [
                    ['정산 처리됨 (정산완료)', $os['done']['n'], $os['done']['amt'], $os['done']['note']],
                    ['아직 정산 처리 안 됨 (수령완료)', $os['wait']['n'], $os['wait']['amt'], $os['wait']['note']],
                ], 'cls' => []])
                @if ($os['wait']['n'])
                    <p class="text-sm font-bold text-signal">이 기간에 정산 처리 안 된 주문이 {{ $os['wait']['n'] }}건 있어요. 송금을 마쳤다면 원샷 판매자 정산에서 처리해 주세요.</p>
                @elseif ($os['done']['n'])
                    <p class="text-sm font-bold text-emerald-600">이 기간 주문은 모두 정산 처리됐어요.</p>
                @else
                    <p class="text-sm text-mist">이 기간에 수령완료·정산완료 주문이 없어요.</p>
                @endif
            </div>
        @endif

        <script>
        (function () {
            const csrf = @json(csrf_token()), vendorId = @json($vendor->id), loadUrl = @json(route('admin.settlements.pg-load'));
            const send = (url, method, body) => fetch(url, { method, headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
                .then(r => r.json().catch(() => ({ ok: false, msg: '서버 응답 오류 (HTTP ' + r.status + ')' })))
                .catch(() => ({ ok: false, msg: '통신 오류' }));

            // 페이레터에서 불러오기: 날짜마다 1번씩 (한 번에 오래 걸리지 않게)
            const go = document.getElementById('plGo'), msg = document.getElementById('plMsg');
            if (go) go.onclick = async () => {
                const F = document.getElementById('plFrom').value, T = document.getElementById('plTo').value;
                if (!/^\d{4}-\d{2}-\d{2}$/.test(F) || !/^\d{4}-\d{2}-\d{2}$/.test(T) || F > T) { alert('결제일을 바르게 넣어 주세요.'); return; }
                const days = [];
                for (let d = new Date(F + 'T00:00:00Z'); d <= new Date(T + 'T00:00:00Z'); d = new Date(d.getTime() + 864e5)) days.push(d.toISOString().slice(0, 10));
                if (days.length > 93 && !confirm(days.length + '일치를 불러와요. 시간이 걸릴 수 있어요. 계속할까요?')) return;
                go.disabled = true;
                const label = '페이레터 불러오기 ' + F + ' ~ ' + T;
                let loadId = null, got = 0, nw = 0, other = 0, partial = 0; const errs = [];
                for (let i = 0; i < days.length; i++) {
                    msg.textContent = days[i] + ' 불러오는 중… (' + (i + 1) + '/' + days.length + ')';
                    const j = await send(loadUrl, 'POST', { vendor_id: vendorId, date: days[i], load_id: loadId, label });
                    if (!j.ok) { errs.push(days[i] + ' ' + (j.msg || j.message || '실패')); continue; }
                    loadId = j.load_id; got += j.got; nw += j.new; other += j.other || 0; partial += j.partial || 0;
                    (j.errors || []).forEach(x => errs.push(days[i] + ' ' + x));
                }
                let m = label + '\n가져온 줄 ' + got + ' · 새로 저장 ' + nw + ' (이미 있던 줄 ' + (got - nw) + ')';
                if (other) m += '\n다른 업체 온라인 결제 ' + other + '줄은 제외';
                if (partial) m += '\n부분취소 ' + partial + '줄은 건너뜀 — 해당 날짜는 페이레터 관리자 화면에서 확인해 주세요';
                if (errs.length) m += '\n\n오류:\n' + errs.slice(0, 8).join('\n');
                alert(m);
                location.reload();
            };
            document.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => {
                if (!confirm('이때 저장된 줄을 삭제할까요? (같은 기간을 다시 불러오면 복구돼요)')) return;
                const j = await send(b.dataset.del, 'DELETE');
                if (!j.ok) { alert(j.msg || '삭제 실패'); return; }
                location.reload();
            });
        })();
        </script>
    @endif
</div>
@endsection
