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
                <p class="mt-1 font-bold">
                    @if ($auto)
                        {{-- 자동 대사 결과 (원본 #5) --}}
                        @if ($sync)
                            <span class="text-blue-700">페이레터 카드 결제 내역을 맞추는 중이에요… <span id="srSyncMsg" class="font-normal"></span></span>
                        @elseif ($syncErr)
                            <span class="text-signal">✕ 카드 결제 대사 일부 실패 ({{ $syncErr }}일)</span>
                            <a class="btn-ghost ml-2 !px-3 !py-1 text-xs" href="{{ $redo }}">다시 맞추기</a>
                        @elseif (! $clients && ! $synced)
                            <span class="text-signal">페이레터 조회 키 설정이 없어 카드 결제를 자동으로 맞추지 못했어요</span> <span class="font-normal">(.env 의 PAYLETTER_*). 아래 보고서는 저장된 내역 기준이에요.</span>
                        @else
                            <span class="text-emerald-700">✓ 카드 결제 대사 완료</span> <span class="font-normal">— 이 기간 페이레터 승인·취소 내역이 모두 반영되었어요.</span>
                        @endif
                    @elseif ($plDone)
                        <span class="font-normal">이 기간의 페이레터 내역은 이미 다 불러와 있어요. 아래 보고서를 인쇄하거나 엑셀로 내려받아 제출하세요.</span>
                    @else
                        <b class="text-signal">페이레터 내역을 {{ $plFrom }} 부터 아직 안 불러왔어요.</b> <span class="font-normal">아래 [불러오기]를 먼저 누르세요 (날짜는 채워 두었어요).</span>
                    @endif
                </p>
                @if ($src === 'pending')<p class="mt-1">제출 후 <a class="underline" href="{{ route('admin.settlements.index') }}">원샷 판매자 정산</a>에서 [정산완료]를 누르면, 완료목록의 [보고서]로 같은 보고서를 다시 볼 수 있어요.</p>@endif
            </div>
        @endif

        @unless ($auto)
        {{-- 페이레터 불러오기 (하루씩, 누적 저장) — 기간 없이 연 경우만. 원샷 [보고서]로 열면 자동 대사라 숨긴다 --}}
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
        @endunless

        @php $s = $report['sections']; @endphp
        <form method="GET" class="flex flex-wrap items-end gap-2 rounded-3xl bg-white p-4 ring-1 ring-ink/5">
            <input type="hidden" name="vendor" value="{{ $vendor->id }}"><input type="hidden" name="rate" value="{{ $rate }}">
            @if ($src)<input type="hidden" name="src" value="{{ $src }}">@endif
            <div><label class="label" for="from">기간</label><input id="from" type="date" name="from" value="{{ $report['S'] }}" class="field num !py-2"></div>
            <div><label class="label" for="to">~</label><input id="to" type="date" name="to" value="{{ $report['E'] }}" class="field num !py-2"></div>
            <button class="btn-ink !py-2.5 text-sm">기간 적용</button>
            <a class="btn-ghost !py-2.5 text-sm" href="{{ route('admin.settlements.report', ['vendor' => $vendor->id, 'rate' => $rate]) }}">전체 기간</a>
            @if ($s)
                <button type="button" class="btn-ghost !py-2.5 text-sm" onclick="window.print()">보고서 인쇄</button>
                <a class="btn-ghost !py-2.5 text-sm" href="{{ route('admin.settlements.report.export', ['vendor' => $vendor->id, 'rate' => $rate, 'from' => $report['S'], 'to' => $report['E'], 'src' => $src]) }}">엑셀 다운로드</a>
            @endif
            <span class="text-xs text-mist">저장된 카드 승인일: <span class="num">{{ $report['min'] ?: '-' }} ~ {{ $report['max'] ?: '-' }}</span> ({{ number_format($report['rows']) }}줄)</span>
        </form>

        {{-- 보고서 = 원본 데이터 3구역을 가공 없이 그대로 (원본 2026-11-24판: 짝 맞추기·추정 없음) --}}
        <div id="srReport" class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            @if (! $s)
                <p class="text-sm text-mist">이 업체에는 아직 주문도 카드 결제 내역도 없어요.</p>
            @else
                <p class="text-lg font-black">{{ $report['store'] }} 정산 보고서</p>
                <p class="sr-sub">기간 {{ $report['S'] }} ~ {{ $report['E'] }} (1·2 = 다담다 주문일, 3 = 카드 승인일) · 작성일 {{ now()->toDateString() }}</p>

                <h3>1. 다담다 결제내역</h3>
                <p class="sr-sub">다담다 POS·온라인 주문 기록 그대로입니다. 결제수단은 주문할 때 POS에서 고른 값입니다.</p>
                @include('admin.partials.report-table', ['head' => ['결제수단', '건수', '금액'], 'rows' => $s['s1']['sum'], 'cls' => [count($s['s1']['sum']) - 1 => 'tot']])
                @include('admin.partials.report-table', ['head' => ['주문일시', '주문번호', '결제수단', '금액'], 'rows' => $s['s1']['rows'], 'cls' => []])

                <h3>2. 다담다 정산내역</h3>
                <p class="sr-sub">위 주문의 원샷 판매자 정산 처리 기록 그대로입니다. 정산완료 = 원샷 정산 처리됨, 미정산 = 수령완료 상태로 정산예정일이 지나면 원샷 대기목록에 올라옴, 수령 전 = 아직 수령완료 전.</p>
                @include('admin.partials.report-table', ['head' => ['상태', '건수', '금액'], 'rows' => $s['s2']['sum'], 'cls' => [count($s['s2']['sum']) - 1 => 'tot']])
                @if ($s['s2']['byJd'])
                    @include('admin.partials.report-table', ['head' => ['원샷 정산 처리일', '건수', '정산완료 금액'], 'rows' => $s['s2']['byJd'], 'cls' => []])
                @endif
                @include('admin.partials.report-table', ['head' => ['주문일시', '주문번호', '금액', '상태', '정산 처리일', '정산예정일'], 'rows' => $s['s2']['rows'], 'cls' => []])

                <h3>3. PG사(페이레터) 정산내역</h3>
                <p class="sr-sub">페이레터에서 불러온 카드 승인·취소 내역 그대로입니다(승인일 기준). 정산금액은 이 카드결제 합계에서 이용료 {{ \App\Services\SettlementReport::pct($rate) }}%와 부가세를 뺀 금액입니다. "원샷 정산"은 그 승인일이 원샷 정산 처리된 묶음 기간에 들어 있는지입니다.@unless ($s['s3']['hasS']) 페이레터 거래내역 조회에는 PG 입금일이 없어 입금일 칸은 표시하지 않습니다.@endunless</p>
                @include('admin.partials.report-table', ['head' => ['구분', '줄 수', '금액'], 'rows' => $s['s3']['sum'], 'cls' => $s['s3']['cls']])
                @include('admin.partials.report-table', ['head' => $s['s3']['head'], 'rows' => $s['s3']['rows'], 'cls' => []])
            @endif
        </div>

        <script>
        (function () {
            const csrf = @json(csrf_token()), vendorId = @json($vendor->id), loadUrl = @json(route('admin.settlements.pg-load'));
            const send = (url, method, body) => fetch(url, { method, headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
                .then(r => r.json().catch(() => ({ ok: false, msg: '서버 응답 오류 (HTTP ' + r.status + ')' })))
                .catch(() => ({ ok: false, msg: '통신 오류' }));

            const days = (F, T) => { const a = []; for (let d = new Date(F + 'T00:00:00Z'); d <= new Date(T + 'T00:00:00Z'); d = new Date(d.getTime() + 864e5)) a.push(d.toISOString().slice(0, 10)); return a; };

            // 페이레터에서 불러오기: 날짜마다 1번씩 (한 번에 오래 걸리지 않게). 수동 [불러오기]·자동 대사 공용 (원본 plFetch)
            //  prog(day, k, n) = 진행 표시, 돌려주는 값 = {got, nw, other, partial, errs, errDays}
            async function plFetch(F, T, label, prog) {
                const list = days(F, T);
                let loadId = null, got = 0, nw = 0, other = 0, partial = 0; const errs = [], bad = new Set();
                for (let i = 0; i < list.length; i++) {
                    prog(list[i], i + 1, list.length);
                    const j = await send(loadUrl, 'POST', { vendor_id: vendorId, date: list[i], load_id: loadId, label });
                    if (!j.ok) { errs.push(list[i] + ' ' + (j.msg || j.message || '실패')); bad.add(list[i]); continue; }
                    loadId = j.load_id; got += j.got; nw += j.new; other += j.other || 0; partial += j.partial || 0;
                    (j.errors || []).forEach(x => { errs.push(list[i] + ' ' + x); bad.add(list[i]); });
                }
                return { got, nw, other, partial, errs, errDays: bad.size };
            }

            const go = document.getElementById('plGo'), msg = document.getElementById('plMsg');
            if (go) go.onclick = async () => {
                const F = document.getElementById('plFrom').value, T = document.getElementById('plTo').value;
                if (!/^\d{4}-\d{2}-\d{2}$/.test(F) || !/^\d{4}-\d{2}-\d{2}$/.test(T) || F > T) { alert('결제일을 바르게 넣어 주세요.'); return; }
                const n = days(F, T).length;
                if (n > 93 && !confirm(n + '일치를 불러와요. 시간이 걸릴 수 있어요. 계속할까요?')) return;
                go.disabled = true;
                const label = '페이레터 불러오기 ' + F + ' ~ ' + T;
                const r = await plFetch(F, T, label, (day, k, n) => { msg.textContent = day + ' 불러오는 중… (' + k + '/' + n + ')'; });
                let m = label + '\n가져온 줄 ' + r.got + ' · 새로 저장 ' + r.nw + ' (이미 있던 줄 ' + (r.got - r.nw) + ')';
                if (r.other) m += '\n다른 업체 온라인 결제 ' + r.other + '줄은 제외';
                if (r.partial) m += '\n부분취소 ' + r.partial + '줄은 건너뜀 — 해당 날짜는 페이레터 관리자 화면에서 확인해 주세요';
                if (r.errs.length) m += '\n\n오류:\n' + r.errs.slice(0, 8).join('\n');
                alert(m);
                location.reload();
            };

            // 원샷 [보고서]로 연 경우: 자동 대사 — 화면 전체 로딩을 띄우고 확정 안 된 날만 불러온 뒤 &synced=1(실패 있으면 &syncerr=N)로 새로 고침
            const SR_SYNC = @json($sync);
            if (SR_SYNC) {
                const ov = document.createElement('div');
                ov.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(255,255,255,.88);display:flex;align-items:center;justify-content:center;';
                ov.innerHTML = '<div style="text-align:center;font-size:16px;color:#1e3a8a;line-height:1.8;"><b>카드 결제 내역을 맞추는 중입니다…</b><br><span id="srSyncOv" style="font-size:14px;color:#555;"></span><br><span style="font-size:12px;color:#888;">페이레터 ' + SR_SYNC.from + ' ~ ' + SR_SYNC.to + ' 조회 · 창을 닫지 마세요</span></div>';
                document.body.appendChild(ov);
                const o = document.getElementById('srSyncOv'), s = document.getElementById('srSyncMsg');
                plFetch(SR_SYNC.from, SR_SYNC.to, '원샷 보고서 자동 대사 ' + SR_SYNC.from + ' ~ ' + SR_SYNC.to, (day, k, n) => {
                    o.textContent = day + ' (' + k + '/' + n + ')'; if (s) s.textContent = day + ' (' + k + '/' + n + ')';
                }).then(r => {
                    const u = new URL(location.href); u.searchParams.delete('synced'); u.searchParams.delete('syncerr');
                    u.searchParams.set('synced', '1'); if (r.errDays) u.searchParams.set('syncerr', r.errDays);
                    location.replace(u.toString());
                });
            }
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
