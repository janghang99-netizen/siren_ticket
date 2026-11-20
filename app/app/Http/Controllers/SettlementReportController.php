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

        return view('admin.settlement-report', [
            'vendors' => Vendor::orderBy('name')->get(['id', 'name']),
            'vendor' => $vendor, 'rate' => $rate, 'from' => $from, 'to' => $to, 'src' => $src,
            'report' => $report,
            'loads' => $vendor ? DB::table('settle_pg_loads')->where('vendor_id', $vendor->id)->orderByDesc('id')->get() : collect(),
            'clients' => $this->pg->publicClients(),
            'storeCodes' => $vendor ? $vendor->stores()->pluck('code')->all() : [],
            'plFrom' => $plFrom, 'plTo' => $plTo, 'plDone' => $plDone,
        ]);
    }

    /** 보고서 표를 엑셀에서 열리는 CSV(UTF-8 BOM)로 */
    public function export(Request $request): StreamedResponse
    {
        [$vendor, $rate, $from, $to, $src] = $this->params($request);
        abort_unless($vendor, 404);
        $r = $this->reports->build($vendor, $from, $to, $rate, $src);
        abort_unless($r['sections'], 404, '이 기간에 카드 결제 내역이 없어요.');
        $s = $r['sections'];
        $pct = SettlementReport::pct($rate);
        $num = fn ($v) => is_int($v) || is_float($v) ? (int) round($v) : $v;
        $blocks = [
            [[$r['store'].' 정산 보고서'], ['승인일', $r['S'].' ~ '.$r['E']], ['기준', "PG 카드결제 · 이용료 {$pct}% + 부가세 ".SettlementReport::pct($r['vat']).'%'], ['작성일', now()->toDateString()]],
            [['정산 내역'], ['구분', '줄 수', '금액'], ...$s['sum'][0]],
            [['일자별'], ['구매일', '결제방법', '정산일(PG 입금일)', '건수', '결제금액(취소 반영)', "이용료 {$pct}%", '부가세', '정산금액'], ...$s['day'][0]],
        ];
        if ($s['direct'][0]) {
            $blocks[] = [['매장 직접 수령 — 현금·계좌이체 (참고, 정산 대상 아님)'], ['주문일시', '결제수단', '금액', '주문번호'], ...$s['direct'][0], ['합계', '', $s['direct'][1], '']];
        }
        if ($s['wrong'][0]) {
            $blocks[] = [['현금·계좌이체로 주문했지만 카드로 결제된 건 (정산내역 카드에 이미 포함)'], ['주문일시', 'POS 결제수단', '카드 승인일', '금액', '주문번호'], ...$s['wrong'][0], ['합계', '', '', $s['wrong'][1], '']];
        }
        $blocks[] = [['EIS 매출표와의 차이'], ['구분', '금액'], ...$s['bridge'][0]];
        $blocks[] = [['월별'], ['월', '주문(EIS 기준)', '그중 현금·계좌이체', '카드 주문', 'PG 카드결제', '차이(카드 주문−PG)'], ...$s['month'][0]];
        foreach ([['pgOnly', 'POS 주문 없이 카드로만 받은 결제', ['PG 승인일', '구분', '금액', '주문번호(온라인)']],
            ['sysOnly', 'POS에 카드로 입력했지만 카드 승인이 없는 주문', ['주문일시', 'POS 결제수단', '금액', '주문번호']],
            ['split', '한 주문을 카드 여러 번으로 나눠 결제한 건 (금액 차이 없음)', ['주문일시', 'POS 주문 금액', '카드 승인 (승인일 금액)', '주문번호']]] as [$k, $title, $head]) {
            if ($s[$k][0]) {
                $blocks[] = [[$title.' ('.count($s[$k][0]).'건)'], $head, ...$s[$k][0]];
            }
        }
        $name = '정산보고서_'.preg_replace('/[\\\\\/:*?"<>|]/u', '_', $r['store']).'_'.$r['S'].'_'.$r['E'].'.csv';

        return response()->streamDownload(function () use ($blocks, $num) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($blocks as $block) {
                foreach ($block as $row) {
                    fputcsv($out, array_map($num, $row));
                }
                fputcsv($out, []);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
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
