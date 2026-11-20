<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * 판매자 정산 보고서 (원본 다담다 admin/openmarket_settle_report.php, 2026-11-20~21). 조회 전용.
 *
 *  정산 기준 = 저장된 PG 카드결제(승인 − 취소, 승인일 기준) − 이용료 rate% − 부가세(이용료의 10%)
 *  현금·계좌이체는 매장 직접 수령이라 정산 제외(참고 목록). 이관 앱 주문과 짝지어
 *  "현금으로 입력했지만 카드로 결제된 건", EIS 매출(주문일 기준, 현금 포함)과의 차이를 설명한다.
 *  원본은 브라우저(JS)에서 계산했고, 여기서는 같은 순서·같은 규칙으로 서버에서 계산한다.
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
            ->get(['approved_on', 'settled_on', 'kind', 'order_no', 'amount'])
            ->map(fn ($r) => ['day' => substr((string) $r->approved_on, 0, 10), 'sday' => $r->settled_on ? substr((string) $r->settled_on, 0, 10) : '',
                'term' => $r->kind === 'terminal', 'ono' => (string) $r->order_no, 'amt' => (int) $r->amount])
            ->all();
    }

    /** 대조·참고용 주문: 업체 매장의 결제완료~정산완료(2~6) 주문, 주문 단위 */
    public function orders(Vendor $vendor): array
    {
        return Order::whereIn('store_id', $vendor->stores()->pluck('id'))->whereIn('status', [2, 3, 4, 5, 6])
            ->orderBy('created_at')->orderBy('id')
            ->get(['order_no', 'created_at', 'pay_method', 'total_amount', 'status', 'settled_at'])
            ->map(function (Order $o) {
                [$g, $lab] = self::classify((string) $o->pay_method);
                $amt = (int) $o->total_amount;
                $st = $o->status instanceof \BackedEnum ? $o->status->value : (int) $o->status;

                return ['o' => (string) $o->order_no, 't' => $o->created_at->format('Y-m-d H:i'), 'day' => $o->created_at->toDateString(), 'g' => $g, 'lab' => $lab, 'amt' => $amt,
                    // EIS 로 넘어가는 금액 = 수령완료(5)·정산완료(6) — EisService::exportOrders 와 같은 기준(주문일)
                    'eis' => in_array($st, [5, 6], true) ? $amt : 0,
                    // 원샷 정산 처리 현황: 5 = 아직 정산서 전, 6 = 정산 처리됨
                    's5' => $st === 5 ? $amt : 0, 's6' => $st === 6 ? $amt : 0, 'jd' => $st === 6 && $o->settled_at ? $o->settled_at->format('Y-m-d H:i') : ''];
            })->all();
    }

    /**
     * @return array{store:string, S:string, E:string, min:string, max:string, rows:int, rate:float, vat:float, done:bool, sections:?array, oneshot:array}
     */
    public function build(Vendor $vendor, ?string $from, ?string $to, float $rate, string $src = ''): array
    {
        $PG = $this->pgRows($vendor);
        $days = array_column($PG, 'day');
        $min = $days ? min($days) : '';
        $max = $days ? max($days) : '';
        $S = PgSettlementService::isYmd($from) ? $from : $min;
        $E = PgSettlementService::isYmd($to) ? $to : $max;
        $vatRate = (float) config('siren.card_settle_vat');
        $ORD = $this->orders($vendor);
        $out = ['store' => PgSettlementService::storeName($vendor), 'S' => $S, 'E' => $E, 'min' => $min, 'max' => $max, 'rows' => count($PG),
            'rate' => $rate, 'vat' => $vatRate, 'done' => false, 'sections' => null, 'oneshot' => $this->oneshot($ORD, $S, $E)];
        if (! $PG || $S === '' || $E === '') {
            return $out;
        }

        $all = array_values(array_filter($PG, fn ($x) => $x['day'] >= $S && $x['day'] <= $E));
        if (! $all) {
            return $out;
        }
        $dn = fn (string $d) => intdiv((int) strtotime($d.' 00:00:00 UTC'), 86400);
        $sum = fn (array $a, callable $f) => array_sum(array_map($f, $a));
        $seg = function (callable $f) use ($all) {
            $a = array_filter($all, $f);

            return ['n' => count($a), 'amt' => array_sum(array_column($a, 'amt'))];
        };
        $tA = $seg(fn ($x) => $x['term'] && $x['amt'] > 0);
        $tC = $seg(fn ($x) => $x['term'] && $x['amt'] < 0);
        $oA = $seg(fn ($x) => ! $x['term'] && $x['amt'] > 0);
        $oC = $seg(fn ($x) => ! $x['term'] && $x['amt'] < 0);
        $card = (int) array_sum(array_column($all, 'amt'));
        [$fee, $vat, $pay] = PgSettlementService::split($card, $rate);
        // 완료목록에서 연 정산 묶음(같은 기간)이면 이미 송금한 내역
        $done = $src === 'done' && $from === $S && $to === $E;
        $payLab = $done ? '정산 송금 완료 금액' : '지급할 정산금액';

        // 1) 정산 내역
        $sumRows = [['현장 카드단말기 승인', $tA['n'], $tA['amt']]];
        $sumCls = [''];
        foreach ([[$tC, '현장 카드단말기 취소'], [$oA, '온라인 결제 승인'], [$oC, '온라인 결제 취소']] as [$s, $lab]) {
            if ($s['n']) {
                $sumRows[] = [$lab, $s['n'], $s['amt']];
                $sumCls[] = '';
            }
        }
        array_push($sumRows, ['카드결제 합계 (취소 반영)', count($all), $card], ['이용료 '.self::pct($rate).'%', '', -$fee], ['부가세 (이용료의 '.self::pct($vatRate).'%)', '', -$vat], [$payLab, '', $pay]);
        array_push($sumCls, 'tot', '', '', 'grand');

        // 2) 일자별 — 구매일(승인일) + 정산일. 같은 구매일이라도 정산일이 다르면 줄을 나눈다
        $byDay = [];
        foreach ($all as $x) {
            $k = $x['day'].'|'.$x['sday'];
            $byDay[$k] ??= ['d' => $x['day'], 's' => $x['sday'] ?: '-', 'n' => 0, 'amt' => 0];
            $byDay[$k]['n']++;
            $byDay[$k]['amt'] += $x['amt'];
        }

        // 주문과 짝짓기 — 취소는 같은 금액의 승인과 상쇄 (단말기는 같은 날, 온라인은 같은 주문번호)
        $appr = [];
        foreach ($all as $x) {
            if ($x['amt'] > 0) {
                $appr[] = $x + ['x' => false, 'm' => false];
            }
        }
        foreach ($all as $c) {
            if ($c['amt'] >= 0) {
                continue;
            }
            foreach ($appr as $i => $a) {
                if (! $a['x'] && $a['amt'] === -$c['amt'] && $a['term'] === $c['term'] && ($c['term'] ? $a['day'] === $c['day'] : $a['ono'] === $c['ono'])) {
                    $appr[$i]['x'] = true;
                    break;
                }
            }
        }
        $pg = array_values(array_filter($appr, fn ($a) => ! $a['x']));
        foreach ($pg as $i => $p) {
            $pg[$i]['dn'] = $dn($p['day']);
        }
        $ords = [];
        foreach ($ORD as $o) {
            $o['dn'] = $dn($o['day']);
            if ($o['dn'] >= $dn($S) - 5 && $o['dn'] <= $dn($E) + 5) {
                $ords[] = $o + ['m' => false];
            }
        }
        $inR = fn ($o) => $o['day'] >= $S && $o['day'] <= $E;
        $mine = array_keys(array_filter($ords, fn ($o) => $o['g'] === 'pg'));
        $cash = array_keys(array_filter($ords, fn ($o) => $o['g'] === 'cash'));

        // ① 온라인: 주문번호 + 금액
        foreach ($pg as $pi => $p) {
            if ($p['term']) {
                continue;
            }
            foreach ($mine as $i) {
                if (! $ords[$i]['m'] && $ords[$i]['o'] === $p['ono'] && $ords[$i]['amt'] === $p['amt']) {
                    $ords[$i]['m'] = $pg[$pi]['m'] = true;
                    break;
                }
            }
        }
        // ② 같은 금액, 날짜 차이 0일부터 5일까지 가까운 순
        for ($dd = 0; $dd <= 5; $dd++) {
            foreach ($pg as $pi => $p) {
                if ($pg[$pi]['m']) {
                    continue;
                }
                foreach ($mine as $i) {
                    if (! $ords[$i]['m'] && $ords[$i]['amt'] === $p['amt'] && abs($ords[$i]['dn'] - $p['dn']) === $dd) {
                        $ords[$i]['m'] = $pg[$pi]['m'] = true;
                        break;
                    }
                }
            }
        }
        // ③ 주문 2건의 합 = 승인 1건 (±1일)
        foreach ($pg as $pi => $p) {
            if ($pg[$pi]['m']) {
                continue;
            }
            $c = array_values(array_filter($mine, fn ($i) => ! $ords[$i]['m'] && abs($ords[$i]['dn'] - $p['dn']) <= 1));
            for ($a = 0, $found = false; $a < count($c) && ! $found; $a++) {
                for ($b = $a + 1; $b < count($c); $b++) {
                    if ($ords[$c[$a]]['amt'] + $ords[$c[$b]]['amt'] === $p['amt']) {
                        $ords[$c[$a]]['m'] = $ords[$c[$b]]['m'] = $pg[$pi]['m'] = $found = true;
                        break;
                    }
                }
            }
        }
        // ④ 주문 1건 = 단말기 승인 2~3건의 합 (같은 날 먼저, 그다음 ±1일)
        $splits = [];
        for ($sd = 0; $sd <= 1; $sd++) {
            foreach ($mine as $oi) {
                if ($ords[$oi]['m']) {
                    continue;
                }
                $c = array_values(array_filter(array_keys($pg), fn ($pi) => ! $pg[$pi]['m'] && $pg[$pi]['term'] && abs($ords[$oi]['dn'] - $pg[$pi]['dn']) === $sd));
                $want = $ords[$oi]['amt'];
                $hit = null;
                for ($a = 0; $a < count($c) && ! $hit; $a++) {
                    for ($b = $a + 1; $b < count($c) && ! $hit; $b++) {
                        if ($pg[$c[$a]]['amt'] + $pg[$c[$b]]['amt'] === $want) {
                            $hit = [$c[$a], $c[$b]];
                            break;
                        }
                        for ($k = $b + 1; $k < count($c); $k++) {
                            if ($pg[$c[$a]]['amt'] + $pg[$c[$b]]['amt'] + $pg[$c[$k]]['amt'] === $want) {
                                $hit = [$c[$a], $c[$b], $c[$k]];
                                break;
                            }
                        }
                    }
                }
                if ($hit) {
                    $ords[$oi]['m'] = true;
                    foreach ($hit as $pi) {
                        $pg[$pi]['m'] = true;
                    }
                    $splits[] = [$ords[$oi], array_map(fn ($pi) => $pg[$pi], $hit)];
                }
            }
        }
        // ⑤ 현금·계좌이체로 입력했지만 같은 금액 카드 승인이 ±3일 안에 있는 주문 → 카드 결제로 본다
        $wrong = [];
        foreach ($pg as $pi => $p) {
            if ($pg[$pi]['m']) {
                continue;
            }
            foreach ($cash as $i) {
                if (! $ords[$i]['m'] && $ords[$i]['amt'] === $p['amt'] && abs($ords[$i]['dn'] - $p['dn']) <= 3) {
                    $ords[$i]['m'] = $pg[$pi]['m'] = true;
                    $wrong[] = [$p, $ords[$i]];
                    break;
                }
            }
        }

        // 3) 매장 직접 수령 (참고) — 카드로 확인된 건은 제외
        $direct = array_values(array_filter(array_map(fn ($i) => $ords[$i], $cash), fn ($o) => ! $o['m'] && $inR($o)));
        $dirRows = array_map(fn ($o) => [$o['t'], $o['lab'], $o['amt'], $o['o']], $direct);
        $dirSum = $sum($direct, fn ($o) => $o['amt']);

        // 2-2) 일자별 표 — 카드(PG) + 현금·이체(매장 직접 수령, 이용료·정산 없음)
        $items = array_map(fn ($b) => ['d' => $b['d'], 'm' => '카드', 's' => $b['s'], 'n' => $b['n'], 'amt' => $b['amt']], array_values($byDay));
        $cashBy = [];
        foreach ($direct as $o) {
            $m = str_contains($o['lab'], '현금') ? '현금' : '이체';
            $k = $o['day'].'|'.$m;
            $cashBy[$k] ??= ['d' => $o['day'], 'm' => $m, 's' => '매장 직접 수령', 'n' => 0, 'amt' => 0];
            $cashBy[$k]['n']++;
            $cashBy[$k]['amt'] += $o['amt'];
        }
        $items = array_merge($items, array_values($cashBy));
        $mOrd = ['카드' => 0, '현금' => 1, '이체' => 2];
        usort($items, fn ($a, $b) => [$a['d'], $mOrd[$a['m']], $a['s']] <=> [$b['d'], $mOrd[$b['m']], $b['s']]);
        $dayRows = array_map(function ($it) use ($rate) {
            if ($it['m'] !== '카드') {
                return [$it['d'], $it['m'], $it['s'], $it['n'], $it['amt'], '-', '-', '-'];
            }
            [$f, $v, $p] = PgSettlementService::split($it['amt'], $rate);

            return [$it['d'], $it['m'], $it['s'], $it['n'], $it['amt'], $f, $v, $p];
        }, $items);
        $dayCls = array_fill(0, count($dayRows), '');
        $subOf = function (string $m) use ($items) {
            $a = array_filter($items, fn ($it) => $it['m'] === $m);

            return ['n' => array_sum(array_column($a, 'n')), 'amt' => array_sum(array_column($a, 'amt'))];
        };
        $cCash = $subOf('현금');
        $cBank = $subOf('이체');
        $dayRows[] = ['카드 합계', '카드', '', count($all), $card, $fee, $vat, $pay];
        $dayCls[] = 'tot';
        if ($cCash['n']) {
            $dayRows[] = ['현금 합계', '현금', '매장 직접 수령', $cCash['n'], $cCash['amt'], '-', '-', '-'];
            $dayCls[] = 'tot';
        }
        if ($cBank['n']) {
            $dayRows[] = ['이체 합계', '이체', '매장 직접 수령', $cBank['n'], $cBank['amt'], '-', '-', '-'];
            $dayCls[] = 'tot';
        }
        $dayRows[] = ['전체 매출', '', '', count($all) + $cCash['n'] + $cBank['n'], $card + $cCash['amt'] + $cBank['amt'], $fee, $vat, $pay];
        $dayCls[] = 'grand';

        // 4) 현금·이체로 입력했지만 카드로 결제된 건 (위 정산에 이미 포함)
        $wrongRows = array_map(fn ($w) => [$w[1]['t'], $w[1]['lab'], $w[0]['day'], $w[0]['amt'], $w[1]['o']], $wrong);
        $wrongSum = $sum($wrong, fn ($w) => $w[0]['amt']);

        // 5) EIS 매출표와의 차이 — EIS = 주문(수령완료·정산완료, 주문일, 현금·이체 포함) / 보고서 = PG 카드 승인(승인일)
        $pgOnly = array_values(array_filter($pg, fn ($p) => ! $p['m']));
        $sysOnly = array_values(array_filter(array_map(fn ($i) => $ords[$i], $mine), fn ($o) => ! $o['m'] && $inR($o)));
        $eisIn = array_values(array_filter($ords, $inR));
        $eisA = $sum($eisIn, fn ($o) => $o['eis']);
        $eisCash = $sum(array_filter($eisIn, fn ($o) => $o['g'] === 'cash'), fn ($o) => $o['eis']);
        $pgOnlySum = $sum($pgOnly, fn ($p) => $p['amt']);
        $sysOnlySum = $sum($sysOnly, fn ($o) => $o['eis']);
        $resid = $card - ($eisA - $eisCash + $wrongSum + $pgOnlySum - $sysOnlySum);
        $splitSum = $sum($splits, fn ($x) => $x[0]['amt']);
        $bridgeRows = [
            ["주문 합계 (EIS 매출표 기준 · 주문일 {$S} ~ {$E})", $eisA],
            ['− 현금·계좌이체로 입력된 주문', -$eisCash],
            ['+ 그중 실제로는 카드로 결제된 건 (위 표)', $wrongSum],
            ['+ POS 주문 없이 카드로만 받은 결제 (아래 표)', $pgOnlySum],
            ['− POS에 카드로 입력했지만 카드 승인이 없는 주문 (아래 표)', -$sysOnlySum],
        ];
        if ($splits) {
            $bridgeRows[] = ['· 한 주문을 카드 여러 번으로 나눠 결제 '.count($splits).'건 '.number_format($splitSum).'원 (금액 차이 없음 · 아래 표)', 0];
        }
        $bridgeRows[] = ['± 주문일·승인일 차이, 취소 시점 등 기타', $resid];
        $bridgeRows[] = ['= PG 카드결제 합계 (정산 기준)', $card];
        $bridgeCls = array_map(fn ($i) => $i === 0 ? 'tot' : ($i === count($bridgeRows) - 1 ? 'grand' : ''), array_keys($bridgeRows));

        $mon = [];
        foreach ($eisIn as $o) {
            $m = substr($o['day'], 0, 7);
            $mon[$m] ??= ['eis' => 0, 'cash' => 0, 'pg' => 0];
            $mon[$m]['eis'] += $o['eis'];
            if ($o['g'] === 'cash') {
                $mon[$m]['cash'] += $o['eis'];
            }
        }
        foreach ($all as $x) {
            $m = substr($x['day'], 0, 7);
            $mon[$m] ??= ['eis' => 0, 'cash' => 0, 'pg' => 0];
            $mon[$m]['pg'] += $x['amt'];
        }
        ksort($mon);
        $monRows = [];
        foreach ($mon as $m => $b) {
            $monRows[] = [(string) $m, $b['eis'], $b['cash'], $b['eis'] - $b['cash'], $b['pg'], ($b['eis'] - $b['cash']) - $b['pg']];
        }
        $monRows[] = ['합계', $eisA, $eisCash, $eisA - $eisCash, $card, ($eisA - $eisCash) - $card];
        $monCls = array_map(fn ($i) => $i === count($monRows) - 1 ? 'tot' : '', array_keys($monRows));

        $md = fn (string $d) => substr($d, 5);
        $out['done'] = $done;
        $out['sections'] = [
            'payLab' => $payLab, 'card' => $card, 'fee' => $fee, 'vat' => $vat, 'pay' => (int) round($pay),
            'sum' => [$sumRows, $sumCls], 'day' => [$dayRows, $dayCls],
            'direct' => [$dirRows, $dirSum], 'wrong' => [$wrongRows, $wrongSum],
            'bridge' => [$bridgeRows, $bridgeCls], 'month' => [$monRows, $monCls],
            'pgOnly' => [array_map(fn ($p) => [$p['day'], $p['term'] ? '카드단말기' : '온라인', $p['amt'], $p['term'] ? '' : $p['ono']], $pgOnly), $pgOnlySum],
            'sysOnly' => [array_map(fn ($o) => [$o['t'], $o['lab'], $o['eis'], $o['o']], $sysOnly), $sysOnlySum],
            'split' => [array_map(fn ($x) => [$x[0]['t'], $x[0]['amt'], implode(' + ', array_map(fn ($p) => $md($p['day']).' '.number_format($p['amt']), $x[1])), $x[0]['o']], $splits), $splitSum],
        ];

        return $out;
    }

    /** 원샷 정산 처리 현황 — 주문일 S~E 의 주문 기준 (관리자 확인용) */
    private function oneshot(array $ORD, string $S, string $E): array
    {
        $in = array_filter($ORD, fn ($o) => $S !== '' && $o['day'] >= $S && $o['day'] <= $E);
        $done = array_filter($in, fn ($o) => $o['s6'] > 0);
        $wait = array_filter($in, fn ($o) => $o['s5'] > 0);
        $jds = [];
        foreach ($done as $o) {
            if ($o['jd']) {
                $jds[$o['jd']] = ($jds[$o['jd']] ?? 0) + $o['s6'];
            }
        }
        ksort($jds);
        $waitDays = array_column($wait, 'day');
        sort($waitDays);

        return [
            'done' => ['n' => count($done), 'amt' => array_sum(array_column($done, 's6')), 'note' => $jds ? '정산 처리일: '.implode(', ', array_map(fn ($k, $v) => $k.' ('.number_format($v).'원)', array_keys($jds), $jds)) : '-'],
            'wait' => ['n' => count($wait), 'amt' => array_sum(array_column($wait, 's5')), 'note' => $waitDays ? '주문일 '.$waitDays[0].' ~ '.end($waitDays) : '-'],
        ];
    }

    public static function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
