<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Settlement;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * 판매자 정산 보고서 (원본 다담다 admin/openmarket_settle_report.php, 2026-11-24 3구역판). 조회 전용.
 *
 *  원본 사용자 지시: "있는 데이터를 그대로, 가공하지 말고 구역을 나눠서 붙여넣어라"
 *  → 주문 ↔ 카드승인 짝 맞추기·재분류·입금일 추정 없이 세 가지 원본 기록을 나란히 보여 준다.
 *   1. 다담다 결제내역 : 주문 기록 (주문일 S~E) — 주문일시·주문번호·POS 결제수단·금액 + 결제수단별 합계
 *   2. 다담다 정산내역 : 같은 주문의 원샷 정산 기록 — 상태·정산 처리일·정산예정일 + 상태별·처리일별 합계
 *   3. PG사(페이레터) 정산내역 : 불러온 승인·취소 (승인일 S~E) 그대로 + 원샷 정산 여부 + 카드결제 기준 정산금액
 */
class SettlementReport
{
    /** 결제수단 → [정산 구분 pg|cash, 표시 이름] (원본 sr_classify). 마일리지 결제는 현장카드 개발 전 임시 구분 → 단말기 */
    public static function classify(string $payMethod): array
    {
        $t = trim($payMethod);

        return match (true) {
            $t === 'pos_card' => ['pg', '현장 카드단말기'],
            $t === 'pos_cash' => ['cash', '현장 현금'],
            $t === 'pos_transfer' => ['cash', '현장 계좌이체'],
            $t === 'card', $t === '' => ['pg', '온라인'],
            str_contains($t, '마일리지') => ['pg', '현장 카드단말기'],
            str_contains($t, '무통장') => ['cash', '무통장입금'],
            default => ['pg', '온라인 '.$t],
        };
    }

    /** 저장된 PG 줄 (승인일·id 순) */
    public function pgRows(Vendor $vendor): array
    {
        return DB::table('settle_pg_rows')->where('vendor_id', $vendor->id)->orderBy('approved_on')->orderBy('id')
            ->get(['approved_on', 'settled_on', 'kind', 'order_no', 'amount', 'status', 'approval_no'])
            ->map(fn ($r) => ['day' => substr((string) $r->approved_on, 0, 10), 'sday' => $r->settled_on ? substr((string) $r->settled_on, 0, 10) : '',
                'term' => $r->kind === 'terminal', 'ono' => (string) $r->order_no, 'amt' => (int) $r->amount,
                'st' => (string) $r->status ?: ((int) $r->amount < 0 ? '취소' : '승인'), 'ano' => (string) $r->approval_no])
            ->all();
    }

    /** 업체 매장의 결제완료~정산완료(2~6) 주문, 주문 단위 (원본도 상품 행 stats 2~6) */
    public function orders(Vendor $vendor): array
    {
        return Order::whereIn('store_id', $vendor->stores()->pluck('id'))->whereIn('status', [2, 3, 4, 5, 6])
            ->orderBy('created_at')->orderBy('id')
            ->get(['order_no', 'created_at', 'pay_method', 'total_amount', 'status', 'settled_at', 'settle_due_on'])
            ->map(function (Order $o) {
                $st = $o->status instanceof \BackedEnum ? $o->status->value : (int) $o->status;

                return ['o' => (string) $o->order_no, 't' => $o->created_at->format('Y-m-d H:i'), 'day' => $o->created_at->toDateString(),
                    'lab' => self::classify((string) $o->pay_method)[1], 'amt' => (int) $o->total_amount, 'st' => $st,
                    'jd' => $st === 6 && $o->settled_at ? $o->settled_at->format('Y-m-d H:i') : '',
                    'sd' => $st === 5 ? PgSettlementService::orderDueOn($o) : ''];
            })->all();
    }

    /** 원샷 정산 완료 묶음(정산서)의 카드 승인일 기간 — 3구역 "원샷 정산" 칸 (원본 SR_DONEWIN) */
    public function doneWindows(Vendor $vendor): array
    {
        $pg = app(PgSettlementService::class);
        $out = [];
        foreach (Settlement::where('vendor_id', $vendor->id)->orderBy('id')->get() as $s) {
            [$f, $t] = $pg->bundleWindow($s);
            if ($f !== '') {
                $at = Order::where('settlement_id', $s->id)->max('settled_at') ?? $s->created_at;
                $out[] = ['jd' => \Illuminate\Support\Carbon::parse($at)->format('Y-m-d H:i'), 'from' => $f, 'to' => $t];
            }
        }

        return $out;
    }

    /**
     * @return array{store:string, S:string, E:string, min:string, max:string, rows:int, rate:float, vat:float, done:bool, sections:?array}
     */
    public function build(Vendor $vendor, ?string $from, ?string $to, float $rate, string $src = ''): array
    {
        $PG = $this->pgRows($vendor);
        $ORD = $this->orders($vendor);
        $days = array_merge(array_column($PG, 'day'), array_column($ORD, 'day'));
        $pgDays = array_column($PG, 'day');
        $min = $pgDays ? min($pgDays) : '';
        $max = $pgDays ? max($pgDays) : '';
        $S = PgSettlementService::isYmd($from) ? $from : ($days ? min($days) : '');
        $E = PgSettlementService::isYmd($to) ? $to : ($days ? max($days) : '');
        $vatRate = (float) config('siren.card_settle_vat');
        $done = $src === 'done' && PgSettlementService::isYmd($from) && PgSettlementService::isYmd($to);
        $out = ['store' => PgSettlementService::storeName($vendor), 'S' => $S, 'E' => $E, 'min' => $min, 'max' => $max, 'rows' => count($PG),
            'rate' => $rate, 'vat' => $vatRate, 'done' => $done, 'sections' => null];
        if ($S === '' || $E === '') {
            return $out;
        }
        $bump = function (array &$m, string $k, int $v): void {
            $m[$k] ??= ['n' => 0, 'amt' => 0];
            $m[$k]['n']++;
            $m[$k]['amt'] += $v;
        };

        // 1. 다담다 결제내역
        $ords = array_values(array_filter($ORD, fn ($o) => $o['day'] >= $S && $o['day'] <= $E));
        $total = array_sum(array_column($ords, 'amt'));
        $byLab = [];
        foreach ($ords as $o) {
            $bump($byLab, $o['lab'], $o['amt']);
        }
        $t1 = array_map(fn ($k, $v) => [$k, $v['n'], $v['amt']], array_keys($byLab), $byLab);
        $t1[] = ['합계', count($ords), $total];
        $r1 = array_map(fn ($o) => [$o['t'], $o['o'], $o['lab'], $o['amt']], $ords);

        // 2. 다담다 정산내역 (원샷 판매자 정산 처리 기록) — 이관 앱 주문은 상태가 주문 단위 하나라 "일부 정산완료"는 없다
        $st2 = [];
        $jds = [];
        $r2 = [];
        foreach ($ords as $o) {
            $st = match (true) {
                $o['st'] === 6 => '정산완료',
                $o['st'] === 5 => '미정산 (수령완료)',
                default => '수령 전',
            };
            $bump($st2, $st, $o['amt']);
            if ($o['st'] === 6 && $o['jd'] !== '') {
                $bump($jds, $o['jd'], $o['amt']);
            }
            $r2[] = [$o['t'], $o['o'], $o['amt'], $st, $o['jd'], $o['sd']];
        }
        $t2 = [];
        foreach (['정산완료', '일부 정산완료', '미정산 (수령완료)', '수령 전'] as $k) {
            if (isset($st2[$k])) {
                $t2[] = [$k, $st2[$k]['n'], $st2[$k]['amt']];
            }
        }
        $t2[] = ['합계', count($ords), $total];
        ksort($jds);
        $t2b = array_map(fn ($k, $v) => [$k, $v['n'], $v['amt']], array_keys($jds), $jds);

        // 3. PG사(페이레터) 정산내역
        $wins = $this->doneWindows($vendor);
        $pgs = array_values(array_filter($PG, fn ($x) => $x['day'] >= $S && $x['day'] <= $E));
        $hasS = (bool) array_filter($pgs, fn ($x) => $x['sday'] !== '');
        $r3 = array_map(function ($x) use ($wins, $hasS) {
            $w = null;
            foreach ($wins as $c) {
                if ($x['day'] >= $c['from'] && $x['day'] <= $c['to']) {
                    $w = $c;
                    break;
                }
            }
            $row = [$x['day'], $x['term'] ? '카드단말기' : '온라인', $x['st'], $x['amt'], $x['ano'], $x['term'] ? '' : $x['ono']];
            if ($hasS) {
                $row[] = $x['sday'];
            }
            $row[] = $w ? '정산완료 '.$w['jd'] : '미정산';

            return $row;
        }, $pgs);
        $ap = array_filter($pgs, fn ($x) => $x['amt'] > 0);
        $cn = array_filter($pgs, fn ($x) => $x['amt'] < 0);
        $card = array_sum(array_column($pgs, 'amt'));
        [$fee, $vat, $pay] = PgSettlementService::split($card, $rate);
        $payLab = $done ? '정산 송금 완료 금액' : '지급할 정산금액';
        $t3 = [['승인', count($ap), array_sum(array_column($ap, 'amt'))], ['취소', count($cn), array_sum(array_column($cn, 'amt'))],
            ['카드결제 합계 (승인 − 취소)', count($pgs), $card], ['이용료 '.self::pct($rate).'%', '', -$fee],
            ['부가세 (이용료의 '.self::pct($vatRate).'%)', '', -$vat], [$payLab, '', $pay]];
        $h3 = ['승인일', '구분', '승인/취소', '금액', '승인번호', '주문번호 (온라인)'];
        if ($hasS) {
            $h3[] = 'PG 입금일';
        }
        $h3[] = '원샷 정산';

        $out['sections'] = [
            's1' => ['sum' => $t1, 'rows' => $r1],
            's2' => ['sum' => $t2, 'byJd' => $t2b, 'rows' => $r2],
            's3' => ['sum' => $t3, 'cls' => ['', '', 'tot', '', '', 'grand'], 'head' => $h3, 'rows' => $r3, 'hasS' => $hasS, 'payLab' => $payLab,
                'card' => $card, 'pay' => (int) round($pay)],
        ];

        return $out;
    }

    public static function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
