<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Services\Audit;
use App\Services\PgSettlementService;
use App\Services\SettlementReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 운영사: 판매자 정산 보고서 (원본 다담다 openmarket_settle_report.php · ajax_settle_pg.php).
 *  - 페이레터에서 카드 결제내역을 하루씩 불러와 누적 저장 → 카드결제 기준 정산금액 계산
 *  - 조회 전용: 주문·정산서는 바꾸지 않는다 (settle_pg_* 만 쓴다)
 */
class SettlementReportController extends Controller
{
    public function __construct(private PgSettlementService $pg, private SettlementReport $reports) {}

    public function show(Request $request): View
    {
        [$vendor, $rate, $from, $to, $src] = $this->params($request);
        $report = $vendor ? $this->reports->build($vendor, $from, $to, $rate, $src) : null;
        $loadedTo = $vendor ? $this->pg->loadedTo($vendor) : '';

        // 정산 묶음 기간으로 열린 경우: 불러오기 칸 = 아직 안 불러온 날 ~ 묶음 끝
        $plFrom = now()->subDays(7)->toDateString();
        $plTo = now()->subDay()->toDateString();
        $plDone = false;
        if ($from && $to) {
            $plTo = $to;
            $plFrom = $loadedTo !== '' && $loadedTo >= $from ? \Illuminate\Support\Carbon::parse($loadedTo)->addDay()->toDateString() : $from;
            if ($plFrom > $plTo) {
                $plDone = true;
                $plFrom = $from;
            }
        }

        // 원샷 [보고서](from/to/src)로 열면 자동 대사 (원본 2026-11-21 #5·#6): 불러오기 칸·기록 표는 숨기고,
        //  아직 확정 안 된 첫 승인일(그날 + 10일 이후 조회 기록 없음) ~ 묶음 끝을 날짜별로 다시 불러온 뒤 &synced=1 로 새로 고침.
        //  이미 있는 줄은 중복 저장 안 됨. 완료된 옛 묶음은 모두 확정이라 조회하지 않는다.
        $auto = $vendor && $from && $to && $src !== '';
        $sync = null;
        if ($auto && ! $request->has('synced') && $this->pg->clients()) {
            $syncFrom = $this->pg->unfinalFrom($vendor, $from, $to);
            $sync = $syncFrom !== '' ? ['from' => $syncFrom, 'to' => $to] : null;
        }
        $redo = $auto ? $request->fullUrlWithoutQuery(['synced', 'syncerr']) : '';

        return view('admin.settlement-report', [
            'auto' => $auto, 'sync' => $sync, 'synced' => $request->has('synced'), 'syncErr' => max(0, $request->integer('syncerr')), 'redo' => $redo,
            'vendors' => Vendor::orderBy('name')->get(['id', 'name']),
            'vendor' => $vendor, 'rate' => $rate, 'from' => $from, 'to' => $to, 'src' => $src,
            'report' => $report,
            'loads' => $vendor ? DB::table('settle_pg_loads')->where('vendor_id', $vendor->id)->orderByDesc('id')->get() : collect(),
            'clients' => $this->pg->publicClients(),
            'storeCodes' => $vendor ? $vendor->stores()->pluck('code')->all() : [],
            'plFrom' => $plFrom, 'plTo' => $plTo, 'plDone' => $plDone,
        ]);
    }

    /** 보고서를 xlsx 로 — 시트 1개: 종합 요약 + 대사 결과 + 결제·정산 내역 (원본 downloadXlsx 와 같은 구성) */
    public function export(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        [$vendor, $rate, $from, $to, $src] = $this->params($request);
        abort_unless($vendor, 404);
        $r = $this->reports->build($vendor, $from, $to, $rate, $src);
        abort_unless($r['report'], 404, '보고서 기간이 없어요.');
        $x = $r['report'];
        $P = fn (array $rows) => SettlementReport::plain($rows);
        $sheet = [[$r['store'].' 정산 보고서'], ['기간', $r['S'].' ~ '.$r['E']], ['작성일', now()->toDateString()], [],
            ['종합 요약'], $x['smHead'], ...$P($x['sm']), [],
            ['대사 결과'], $x['chkHead'], ...$P($x['chk']), [],
            ['결제·정산 내역'], $P([$x['head']])[0], ...$P($x['rows'])];
        $bin = \App\Services\SimpleXlsx::make(['정산보고서' => $sheet], ['정산보고서' => [17, 18, 8, 26, 13, 13, 14]]);
        $name = '정산보고서_'.preg_replace('/[\\\\\/:*?"<>|]/u', '_', $r['store']).'_'.$r['S'].'_'.$r['E'].'.xlsx';

        return response($bin, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition('attachment', $name, 'settlement-report.xlsx'),
        ]);
    }

    /** 페이레터 하루치 불러오기 — 화면이 날짜마다 1번씩 부른다(타임아웃 방지) */
    public function load(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'load_id' => ['nullable', 'integer'],
            'label' => ['nullable', 'string', 'max:200'],
        ]);
        $vendor = Vendor::findOrFail($data['vendor_id']);
        $res = $this->pg->loadDay($vendor, $data['date'], $data['load_id'] ?? null, (string) ($data['label'] ?? '페이레터 불러오기'), $request->user()?->id);

        return response()->json($res, $res['ok'] ? 200 : 422);
    }

    public function destroyLoad(Vendor $vendor, int $load): JsonResponse
    {
        $n = $this->pg->deleteLoad($vendor, $load);
        Audit::log('settlement.pg_load_deleted', $vendor, "페이레터 불러오기 #{$load} 삭제 ({$n}줄)");

        return response()->json(['ok' => true, 'deleted' => $n]);
    }

    /** @return array{0:?Vendor,1:float,2:?string,3:?string,4:string} */
    private function params(Request $request): array
    {
        $vendor = $request->integer('vendor') ? Vendor::find($request->integer('vendor')) : null;
        $rate = is_numeric($request->query('rate')) ? (float) $request->query('rate') : (float) config('siren.card_settle_rate');
        $rate = max(0, min(100, $rate));
        $from = PgSettlementService::isYmd($request->query('from')) ? $request->query('from') : null;
        $to = PgSettlementService::isYmd($request->query('to')) ? $request->query('to') : null;
        if (! $from || ! $to || $from > $to) {
            $from = $to = null;
        }
        $src = in_array($request->query('src'), ['pending', 'done'], true) ? $request->query('src') : '';

        return [$vendor, $rate, $from, $to, $src];
    }
}
