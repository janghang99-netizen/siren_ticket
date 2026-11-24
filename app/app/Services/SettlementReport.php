<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Settlement;
use App\Models\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 판매자 정산 보고서 (원본 다담다 admin/openmarket_settle_report.php 최종판 a4fec1b2, 2026-11-24). 조회 전용.
 *
 *  순서: 종합 요약 → 대사 결과 → 결제·정산 내역(한 표 7칸) → 표 읽는 법
 *   내역 표 = 결제일 | 결제종류 | 상태 | 구매번호/결제번호/승인번호 | 다담다결제 / EIS | 원샷 처리일 | 페이레터 정산
 *  · 줄 = 다담다 결제(주문) 1건 1줄, 결제일시 순. 그 줄에 같은 결제의 페이레터 승인을 붙인다:
 *      온라인 = 구매번호가 같은 승인 / 카드 = 같은 날 같은 금액의 승인(여럿이면 시각이 가장 가까운 것)
 *  · 다담다에 없는 페이레터 줄은 결제 시각 자리에 끼운다. 취소 줄은 그 승인 줄 바로 아래.
 *  · 못 붙인 카드 주문은 금액으로 한 번 더 맞추고(하루 차이·나눠 결제·합쳐 결제) 줄에 추정이라고 적는다.
 *  원본은 브라우저 JS 로 계산 — 여기서는 같은 순서·같은 규칙을 PHP 로 옮겼다.
 *
 *  셀 = 숫자(오른쪽 정렬·콤마) | 글자 | ['v' => 글자, 'c' => 칸 class(n|sr-ok|sr-mute|sr-bad|sr-no)]
 */
class SettlementReport
{
    /** 원본 product_stats 이름 (취소·환불된 온라인 주문의 상태 표시용) */
    private const STATUS = [0 => '진행중', 1 => '미결제', 2 => '결제완료', 3 => '준비중', 4 => '준비완료', 5 => '수령완료', 6 => '정산완료',
        7 => '구매취소요청', 8 => '반품요청', 9 => '반품성사', 10 => '반품승인', 11 => '판매취소', 12 => '판매취소완료', 14 => '환불완료'];

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

    /** 결제종류: 카드 / 온라인(카카오페이 등) / 현금 / 계좌이체 (원본 kindOf) */
    public static function kindOf(string $g, string $lab): string
    {
        if ($g !== 'pg') {
            return str_contains($lab, '현금') ? '현금' : '계좌이체';
        }
        if (str_starts_with($lab, '온라인')) {
            $n = trim(substr($lab, strlen('온라인')));

            return $n !== '' ? "온라인({$n})" : '온라인';
        }

        return '카드';
    }

    /** 저장된 PG 줄 (승인일·id 순) */
    public function pgRows(Vendor $vendor): array
    {
        return DB::table('settle_pg_rows')->where('vendor_id', $vendor->id)->orderBy('approved_on')->orderBy('id')
            ->get(['approved_on', 'kind', 'order_no', 'amount', 'approval_no', 'evt_time'])
            ->map(fn ($r) => ['day' => substr((string) $r->approved_on, 0, 10), 'term' => $r->kind === 'terminal', 'ono' => (string) $r->order_no,
                'amt' => (int) $r->amount, 'ano' => (string) $r->approval_no, 'tm' => substr((string) $r->evt_time, 0, 16)])
            ->all();
    }

    /** 업체 매장의 결제완료~정산완료(2~6) 주문, 주문 단위. EIS 로 가는 금액 = 수령완료·정산완료(5·6) 주문 */
    public function orders(Vendor $vendor): array
    {
        return Order::whereIn('store_id', $vendor->stores()->pluck('id'))->whereIn('status', [2, 3, 4, 5, 6])
            ->orderBy('created_at')->orderBy('id')
            ->get(['order_no', 'created_at', 'pay_method', 'total_amount', 'status', 'settled_at'])
            ->map(function (Order $o) {
                $st = $o->status instanceof \BackedEnum ? $o->status->value : (int) $o->status;
                [$g, $lab] = self::classify((string) $o->pay_method);
                $amt = (int) $o->total_amount;

                return ['o' => (string) $o->order_no, 't' => $o->created_at->format('Y-m-d H:i'), 'g' => $g, 'lab' => $lab, 'amt' => $amt,
                    'eis' => in_array($st, [5, 6], true) ? $amt : 0, 's6' => $st === 6 ? $amt : 0,
                    'jd' => $st === 6 && $o->settled_at ? $o->settled_at->format('Y-m-d H:i') : ''];
            })->all();
    }

    /** 온라인 페이레터 줄인데 위 주문 목록(2~6)에 없는 주문 — 취소·환불 등. 구매번호로 찾아 상태를 붙인다 (원본 SR_XORD) */
    private function extraOrders(Vendor $vendor, array $PG, array $known): array
    {
        $nos = [];
        foreach ($PG as $p) {
            if (! $p['term'] && $p['ono'] !== '' && ! isset($known[$p['ono']])) {
                $nos[$p['ono']] = true;
            }
        }
        if (! $nos) {
            return [];
        }
        $out = [];
        foreach (Order::whereIn('store_id', $vendor->stores()->pluck('id'))->whereIn('order_no', array_keys($nos))->get(['order_no', 'created_at', 'pay_method', 'total_amount', 'status']) as $o) {
            $st = $o->status instanceof \BackedEnum ? $o->status->value : (int) $o->status;
            $out[(string) $o->order_no] = ['t' => $o->created_at->format('Y-m-d H:i'), 'lab' => self::classify((string) $o->pay_method)[1],
                'amt' => (int) $o->total_amount, 'st' => self::STATUS[$st] ?? '상태 '.$st];
        }

        return $out;
    }

    /** 원샷 정산 완료 묶음(정산서)의 카드 승인일 기간 + 처리일 (원본 SR_DONEWIN) */
    public function doneWindows(Vendor $vendor): array
    {
        $pg = app(PgSettlementService::class);
        $out = [];
        foreach (Settlement::where('vendor_id', $vendor->id)->orderBy('id')->get() as $s) {
            [$f, $t] = $pg->bundleWindow($s);
            if ($f !== '') {
                $at = Order::where('settlement_id', $s->id)->max('settled_at') ?? $s->created_at;
                $out[] = ['jd' => Carbon::parse($at)->format('Y-m-d H:i'), 'from' => $f, 'to' => $t];
            }
        }

        return $out;
    }

    /**
     * @return array{store:string, S:string, E:string, min:string, max:string, rows:int, rate:float, vat:float, done:bool, report:?array}
     */
    public function build(Vendor $vendor, ?string $from, ?string $to, float $rate, string $src = ''): array
    {
        $PG = $this->pgRows($vendor);
        $ORD = $this->orders($vendor);
        $pgDays = array_column($PG, 'day');
        $oDays = array_map(fn ($o) => substr($o['t'], 0, 10), $ORD);
        $min = $pgDays ? min($pgDays) : '';
        $max = $pgDays ? max($pgDays) : '';
        // 기간 기본값 = 저장된 카드 승인일 전체 (원본과 같음). 카드 내역이 없으면 주문일 전체.
        $S = PgSettlementService::isYmd($from) ? $from : ($min !== '' ? $min : ($oDays ? min($oDays) : ''));
        $E = PgSettlementService::isYmd($to) ? $to : ($max !== '' ? $max : ($oDays ? max($oDays) : ''));
        $vatRate = (float) config('siren.card_settle_vat');
        $done = $src === 'done' && PgSettlementService::isYmd($from) && PgSettlementService::isYmd($to);
        $out = ['store' => PgSettlementService::storeName($vendor), 'S' => $S, 'E' => $E, 'min' => $min, 'max' => $max, 'rows' => count($PG),
            'rate' => $rate, 'vat' => $vatRate, 'done' => $done, 'report' => null];
        if ($S === '' || $E === '') {
            return $out;
        }
        $XORD = $this->extraOrders($vendor, $PG, array_flip(array_column($ORD, 'o')));
        $wins = $this->doneWindows($vendor);
        $out['report'] = $this->compute($ORD, $PG, $XORD, $wins, $S, $E, $rate, $vatRate, $done);

        return $out;
    }

    /** 보고서 계산 (원본 render(S, E) 와 같은 순서) */
    public function compute(array $ORD, array $PG, array $XORD, array $wins, string $S, string $E, float $rate, float $vatRate, bool $done): array
    {
        $won = fn ($v) => number_format((int) round($v));
        $day10 = fn ($d) => $d ? substr((string) $d, 0, 10) : '';
        $mins = fn ($t) => ($t && strlen($t) >= 16) ? (int) substr($t, 11, 2) * 60 + (int) substr($t, 14, 2) : -1;
        $NO = fn ($v) => ['v' => $v, 'c' => 'sr-no'];
        $MUTE = fn ($v) => ['v' => $v, 'c' => 'sr-mute'];
        $OK = fn ($v) => ['v' => $v, 'c' => 'sr-ok'];
        $N = fn ($v) => ['v' => $v, 'c' => 'n'];
        $grpOf = fn ($kind) => str_starts_with($kind, '온라인') ? '온라인' : $kind;
        $doneWin = function ($day) use ($wins) {
            foreach ($wins as $w) {
                if ($day >= $w['from'] && $day <= $w['to']) {
                    return $w;
                }
            }

            return null;
        };
        $dayAdd = fn ($d, $k) => Carbon::parse($d)->addDays($k)->toDateString();

        // 기간 안 주문(결제일시 순)·페이레터 줄. 연결 상태는 따로 둔다 (주문 k, 페이레터 i)
        $ords = array_values(array_filter($ORD, fn ($o) => substr($o['t'], 0, 10) >= $S && substr($o['t'], 0, 10) <= $E));
        usort($ords, fn ($a, $b) => strcmp($a['t'], $b['t']));
        $pgs = array_values(array_filter($PG, fn ($x) => $x['day'] >= $S && $x['day'] <= $E));
        // 번호 칸: 구매번호 아래에 페이레터 결제번호(카드 결제만 — 온라인은 구매번호와 같음)·승인번호
        $pgNo = fn ($i) => (($pgs[$i]['term'] && $pgs[$i]['ono'] !== '') ? "\n결제 ".$pgs[$i]['ono'] : '').($pgs[$i]['ano'] !== '' ? "\n승인 ".$pgs[$i]['ano'] : '');
        $OD = $PD = $days = $byOno = [];
        foreach ($ords as $k => $o) {
            $d = substr($o['t'], 0, 10);
            $OD[$d][] = $k;
            $days[$d] = 1;
        }
        foreach ($pgs as $i => $x) {
            $PD[$x['day']][] = $i;
            $days[$x['day']] = 1;
            if (! $x['term'] && $x['amt'] > 0 && $x['ono'] !== '') {
                $byOno[$x['ono']][] = $i;
            }
        }
        $used = $cp = $paired = $outd = $jo = [];          // 페이레터 줄 i 기준
        $opg = $opgs = $ojn = $ohow = [];                  // 주문 k 기준

        // 1차: 다담다 결제 ↔ 페이레터 승인
        foreach ($ords as $k => $o) {
            if ($o['g'] !== 'pg') {
                continue;
            }
            foreach ($byOno[$o['o']] ?? [] as $i) {
                if (empty($used[$i]) && $pgs[$i]['amt'] === $o['amt']) {
                    $used[$i] = 1;
                    $opg[$k] = $i;

                    continue 2;
                }
            }
            $best = null;
            $bd = 1e9;
            $om = $mins($o['t']);
            foreach ($PD[substr($o['t'], 0, 10)] ?? [] as $i) {
                if (empty($used[$i]) && $pgs[$i]['term'] && $pgs[$i]['amt'] === $o['amt']) {
                    $pm = $mins($pgs[$i]['tm']);
                    $dd = ($om >= 0 && $pm >= 0) ? abs($om - $pm) : 1e8;
                    if ($dd < $bd) {
                        $bd = $dd;
                        $best = $i;
                    }
                }
            }
            if ($best !== null) {
                $used[$best] = 1;
                $opg[$k] = $best;
            }
        }
        // 승인 ↔ 그 결제의 취소 (같은 결제번호·구매번호, 번호가 없으면 카드는 같은 날 같은 금액)
        foreach ($pgs as $c => $cx) {
            if ($cx['amt'] >= 0) {
                continue;
            }
            foreach ($pgs as $a => $ax) {
                if (! isset($cp[$a]) && $ax['amt'] === -$cx['amt'] && $ax['term'] === $cx['term']
                    && (($ax['ono'] !== '' && $ax['ono'] === $cx['ono']) || ($cx['term'] && $ax['ono'] === '' && $ax['day'] === $cx['day']))) {
                    $cp[$a] = $c;
                    $paired[$c] = 1;
                    break;
                }
            }
        }

        // 2차(추정): 1차에서 못 붙인 카드(단말기) 주문만. 날짜 다른 주문끼리 합치지 않음, 취소된 승인 제외, 온라인은 구매번호로만.
        // (연결 상태가 계속 바뀌므로 참조로 본다)
        $freeO = function ($day) use (&$opg, &$opgs, &$ojn, $OD, $ords) {
            return array_values(array_filter($day !== '' ? ($OD[$day] ?? []) : array_keys($ords),
                fn ($k) => $ords[$k]['g'] === 'pg' && ! str_starts_with($ords[$k]['lab'], '온라인') && ! isset($opg[$k]) && ! isset($opgs[$k]) && ! isset($ojn[$k])));
        };
        $freeP = function ($day) use (&$used, $cp, $PD, $pgs) {
            return array_values(array_filter($PD[$day] ?? [], fn ($i) => $pgs[$i]['term'] && $pgs[$i]['amt'] > 0 && empty($used[$i]) && ! isset($cp[$i])));
        };
        // (가) 하루 차이: 다음 날 → 전날 같은 금액
        foreach ($freeO('') as $k) {
            $d = substr($ords[$k]['t'], 0, 10);
            foreach ([1, -1] as $dk) {
                foreach ($freeP($dayAdd($d, $dk)) as $i) {
                    if ($pgs[$i]['amt'] === $ords[$k]['amt']) {
                        $used[$i] = 1;
                        $opg[$k] = $i;
                        $ohow[$k] = 'day';

                        continue 3;
                    }
                }
            }
        }
        // (나) 나눠 결제: 같은 날 승인 2~3건의 합 = 주문
        foreach ($freeO('') as $k) {
            $c = $freeP(substr($ords[$k]['t'], 0, 10));
            $amt = $ords[$k]['amt'];
            $n = count($c);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($pgs[$c[$i]]['amt'] + $pgs[$c[$j]]['amt'] === $amt) {
                        $used[$c[$i]] = $used[$c[$j]] = 1;
                        $opgs[$k] = [$c[$i], $c[$j]];

                        continue 3;
                    }
                    for ($m = $j + 1; $m < $n; $m++) {
                        if ($pgs[$c[$i]]['amt'] + $pgs[$c[$j]]['amt'] + $pgs[$c[$m]]['amt'] === $amt) {
                            $used[$c[$i]] = $used[$c[$j]] = $used[$c[$m]] = 1;
                            $opgs[$k] = [$c[$i], $c[$j], $c[$m]];

                            continue 4;
                        }
                    }
                }
            }
        }
        // (다) 합쳐 결제: 같은 날 주문 2~3건의 합 = 승인
        $pdays = array_keys($PD);
        sort($pdays);
        foreach ($pdays as $day) {
            foreach ($freeP($day) as $x) {
                if (! empty($used[$x])) {
                    continue;
                }
                $c = $freeO($day);
                $n = count($c);
                $hit = null;
                $xa = $pgs[$x]['amt'];
                for ($i = 0; $i < $n && ! $hit; $i++) {
                    for ($j = $i + 1; $j < $n && ! $hit; $j++) {
                        if ($ords[$c[$i]]['amt'] + $ords[$c[$j]]['amt'] === $xa) {
                            $hit = [$c[$i], $c[$j]];
                        }
                        for ($m = $j + 1; $m < $n && ! $hit; $m++) {
                            if ($ords[$c[$i]]['amt'] + $ords[$c[$j]]['amt'] + $ords[$c[$m]]['amt'] === $xa) {
                                $hit = [$c[$i], $c[$j], $c[$m]];
                            }
                        }
                    }
                }
                if ($hit) {
                    $used[$x] = 1;
                    $jo[$x] = $hit;
                    foreach ($hit as $k) {
                        $ojn[$k] = $x;
                    }
                }
            }
        }

        // 내역 표
        $L = [];
        $LT = function ($k) use (&$L) {
            $L[$k] ??= ['n' => 0, 'amt' => 0, 'pg' => 0, 'done' => 0, 'wait' => 0];
        };
        $rows = $cls = [];
        $nNoPg = $aNoPg = $nOnly = $aOnly = $eisSum = $eisN = $nDay = $nSplit = $nJoin = $aGuess = 0;
        // 다담다결제 합계와 EIS 매출의 차이 설명: EIS 로 넘어가지 않는 금액을 결제(구매번호)별로 (승인+취소는 상계)
        $EX = [];
        $exAdd = function ($k, $t, $kind, $amt, $note) use (&$EX) {
            $EX[$k] ??= ['t' => $t, 'kind' => $kind, 'net' => 0, 'note' => ''];
            $EX[$k]['net'] += $amt;
            if ($note !== '') {
                $EX[$k]['note'] = $note;
            }
        };
        $first = true;
        $dDa = 0;
        $push = function (array $r) use (&$rows, &$cls, &$first) {
            $rows[] = $r;
            $cls[] = $first ? 'dtop' : '';
            $first = false;
        };
        // 페이레터 줄 1개 (다담다 주문에 붙지 않은 줄 · 또는 주문 결제의 취소 줄)
        $emitPg = function ($i, $kind, $ordNo) use (&$emitPg, &$outd, &$L, $LT, &$dDa, &$nOnly, &$aOnly, $exAdd, $push, $pgs, $XORD, $cp, $paired, $doneWin, $grpOf, $won, $day10, $pgNo, $NO, $MUTE, $OK, $N) {
            if (! empty($outd[$i])) {
                return;
            }
            $outd[$i] = 1;
            $x = $pgs[$i];
            $w = $doneWin($x['day']);
            $st = $x['amt'] < 0 ? '취소' : '승인';
            $xo = (! $x['term'] && $x['ono'] !== '') ? ($XORD[$x['ono']] ?? null) : null;
            if ($kind === '') {
                $kind = $x['term'] ? '카드' : ($xo ? self::kindOf('pg', $xo['lab']) : '온라인');
            }
            $lk = $grpOf($kind).' '.$st;
            $LT($lk);
            $L[$lk]['n']++;
            $L[$lk]['pg'] += $x['amt'];
            if (! $x['term']) {   // 온라인 = 다담다에서 결제한 건(취소는 −), EIS 에는 없음
                $da = $N($won($x['amt']).' (EIS 제외)');
                $L[$lk]['amt'] += $x['amt'];
                $dDa += $x['amt'];
                $exAdd($x['ono'] !== '' ? $x['ono'] : $x['ano'], $x['tm'] ?: ($xo ? $xo['t'] : $x['day']), $kind, $x['amt'],
                    $x['amt'] > 0 ? (($xo ? $xo['st'].' 상태' : '주문 기록 없음').(isset($cp[$i]) ? '' : ', 카드 취소 안 됨')) : '');
            } elseif ($ordNo !== '') {
                $da = '';                                     // POS 주문 결제의 취소 줄
            } else {
                $da = $MUTE('POS에 없음');
                $nOnly++;
                $aOnly += $x['amt'];
            }
            $no = ltrim(($ordNo !== '' ? $ordNo : ($x['term'] ? '' : $x['ono'])).$pgNo($i), "\n");
            // 승인-취소 한 쌍은 정산된 돈이 없으므로 원샷 처리일 빈칸
            $push([$x['tm'] ?: ($xo ? $xo['t'] : $x['day']), $kind, $st, $NO($no), $da, ($w && ! isset($cp[$i]) && empty($paired[$i])) ? $OK($day10($w['jd'])) : '', $x['amt']]);
            if (isset($cp[$i])) {
                $emitPg($cp[$i], $kind, $ordNo);
            }
        };

        $dk = array_keys($days);
        sort($dk);
        foreach ($dk as $d) {
            $first = true;
            $dDa = 0;
            $dPg = 0;
            // 그날의 다담다 결제 + 주문에 붙지 않은 페이레터 줄을 결제 시각 순으로
            $items = array_map(fn ($k) => ['t' => $ords[$k]['t'], 'o' => $k], $OD[$d] ?? []);
            foreach ($PD[$d] ?? [] as $i) {
                $x = $pgs[$i];
                $dPg += $x['amt'];
                if (! empty($used[$i]) || ! empty($paired[$i])) {
                    continue;
                }
                $xt = $x['tm'] ?: ((! $x['term'] && $x['ono'] !== '' && isset($XORD[$x['ono']])) ? $XORD[$x['ono']]['t'] : $x['day'].' 99:99');
                $items[] = ['t' => $xt, 'x' => $i];
            }
            usort($items, fn ($p, $q) => strcmp($p['t'], $q['t']));
            foreach ($items as $it) {
                if (isset($it['x'])) {
                    $emitPg($it['x'], '', '');

                    continue;
                }
                $k = $it['o'];
                $o = $ords[$k];
                $amt = $o['amt'];
                $kind = self::kindOf($o['g'], $o['lab']);
                $lk = $o['g'] === 'pg' ? $grpOf($kind).' 승인' : $kind;
                $LT($lk);
                $pgAdd = 0;
                $noX = '';
                if (isset($opg[$k])) {
                    $p = $pgs[$opg[$k]];
                    $pgAdd = $p['amt'];
                    $noX = $pgNo($opg[$k]);
                    if (($ohow[$k] ?? '') === 'day') {
                        $pc = $N($won($p['amt']).' ('.substr($p['day'], 5).' 결제)');
                        $nDay++;
                        $aGuess += $p['amt'];
                    } else {
                        $pc = $p['amt'];
                    }
                } elseif (isset($opgs[$k])) {
                    $pgAdd = array_sum(array_map(fn ($i) => $pgs[$i]['amt'], $opgs[$k]));
                    $noX = implode('', array_map($pgNo, $opgs[$k]));
                    $pc = $N($won($pgAdd).' (나눠 결제 '.count($opgs[$k]).'건)');
                    $nSplit++;
                    $aGuess += $pgAdd;
                } elseif (isset($ojn[$k])) {
                    $x = $ojn[$k];
                    $noX = $pgNo($x);
                    if ($jo[$x][0] === $k) {
                        $pgAdd = $pgs[$x]['amt'];
                        $pc = $N($won($pgAdd).' ('.count($jo[$x]).'건 합쳐 결제)');
                        $nJoin++;
                        $aGuess += $pgAdd;
                    } else {
                        $pc = $MUTE('합쳐 결제 ('.substr($ords[$jo[$x][0]]['t'], 11).' 줄에 포함)');
                    }
                } elseif ($o['g'] === 'pg') {
                    $pc = ['v' => '승인 없음 (현금·이체 추정)', 'c' => 'sr-bad'];
                    $nNoPg++;
                    $aNoPg += $amt;
                } else {
                    $pc = $MUTE('-');
                }
                $paid = (isset($opg[$k]) && ! isset($cp[$opg[$k]])) || isset($opgs[$k]) || isset($ojn[$k]);
                $L[$lk]['n']++;
                $L[$lk]['amt'] += $amt;
                $dDa += $amt;
                $L[$lk]['pg'] += $pgAdd;
                $L[$lk]['done'] += $o['s6'];
                $L[$lk]['wait'] += $amt - $o['s6'];
                $eis = $o['eis'];
                $eisSum += $eis;
                if ($eis > 0) {
                    $eisN++;
                }
                // EIS 는 수령완료·정산완료 주문만 받는다 → 그 외 금액은 "EIS 제외"
                if ($eis !== $amt) {
                    $exAdd($o['o'], $o['t'], $kind, $amt - $eis, '아직 수령완료 전');
                }
                $dc = $eis === $amt ? $amt : $N($won($amt).($eis > 0 ? ' (EIS '.$won($eis).')' : ' (EIS 제외)'));
                // 카드 승인이 없거나 취소된 결제·현금·이체 = 정산 처리만 되고 보낸 돈은 없음
                $jd = $o['s6'] > 0 ? ($paid ? $OK($day10($o['jd'])) : $MUTE($day10($o['jd']).' (송금 없음)')) : '';
                $push([$o['t'], $kind, '승인', $NO($o['o'].$noX), $dc, $jd, $pc]);
                if (isset($opg[$k]) && isset($cp[$opg[$k]])) {
                    $emitPg($cp[$opg[$k]], $kind, $o['o']);
                }
            }
            foreach ($PD[$d] ?? [] as $i) {   // 짝 없는 취소 줄 등
                if (empty($used[$i]) && empty($outd[$i])) {
                    $emitPg($i, '', '');
                }
            }
            $rows[] = [$d.' 소계', '', '', $NO(''), $dDa, '', $dPg];
            $cls[] = 'dsum';
        }

        // 종합 요약
        $card = array_sum(array_column($pgs, 'amt'));
        [$fee, $vat, $pay] = PgSettlementService::split($card, $rate);
        $dash = $MUTE('-');
        $LK = array_values(array_filter(['카드 승인', '카드 취소', '온라인 승인', '온라인 취소', '현금', '계좌이체'], fn ($k) => isset($L[$k])));
        $sm = [];
        $tA = $tDone = $tWait = $tN = 0;
        foreach ($LK as $k) {
            $l = $L[$k];
            $cash = $k === '현금' || $k === '계좌이체';
            $ord = $l['done'] || $l['wait'];
            $sm[] = [$k, $l['n'], ($ord || str_starts_with($k, '온라인')) ? $l['amt'] : $dash, $ord ? $l['done'] : $dash, $ord ? $l['wait'] : $dash, $cash ? $dash : $l['pg']];
            $tA += $l['amt'];
            $tDone += $l['done'];
            $tWait += $l['wait'];
            $tN += $l['n'];
        }
        $sm[] = ['합계', $tN, $tA, $tDone, $tWait, $card];
        $smCls = array_fill(0, count($sm), '');
        $smCls[count($sm) - 1] = 'tot';
        $sm[] = ['EIS 매출 (수령완료·정산완료 주문)', $eisN, $eisSum, '', '', ''];
        $smCls[] = 'tot';
        $sm[] = ['', '', '', '', '이용료 '.self::pct($rate).'%', -$fee];
        $smCls[] = '';
        $sm[] = ['', '', '', '', '부가세 (이용료의 '.self::pct($vatRate).'%)', -$vat];
        $smCls[] = '';
        $sm[] = ['', '', '', '', $done ? '정산 송금 완료 금액' : '지급할 정산금액', $pay];
        $smCls[] = 'grand';

        // 대사 결과
        $exL = array_values(array_filter($EX, fn ($x) => $x['net'] != 0));
        $chk = [
            ['카드로 입력됐지만 카드 승인이 없는 주문', $nNoPg.'건', $aNoPg, '현금·계좌이체로 받은 것으로 추정됩니다. 카드 승인이 없어 정산금에는 포함되지 않았습니다.'],
            ['POS에 없는 카드 결제', $nOnly.'줄', $aOnly, 'POS 입력 없이 카드 리더기로만 받은 결제입니다(취소 줄 포함). 정산금에는 포함되고 EIS 매출에는 없습니다.'],
            ['금액·날짜가 달라 추정으로 연결한 카드 결제', ($nDay + $nSplit + $nJoin).'건', $aGuess, "하루 차이 {$nDay}건 · 나눠 결제 {$nSplit}건 · 합쳐 결제 {$nJoin}건. 금액으로 추정해 연결했습니다."],
            ['다담다결제 합계 − EIS 매출', count($exL).'건', $tA - $eisSum, $exL
                ? 'EIS로 넘어가지 않은 결제: '.implode(', ', array_map(fn ($x) => $x['t'].' '.$x['kind'].' '.$won($x['net']).'원'.($x['note'] !== '' ? ' ('.$x['note'].')' : ''), $exL)).'.'
                : '차이 없음. 다담다결제 합계 '.$won($tA).'원 = EIS 매출 '.$won($eisSum).'원.'],
        ];

        $help = [
            ['결제일', '다담다 결제일시입니다. 다담다에 없는 페이레터 줄은 페이레터 결제 시각입니다.'],
            ['다담다결제 / EIS', '다담다 결제 금액이며, EIS 매출표로 넘어가는 금액입니다(수령완료·정산완료 주문).'],
            ['(EIS 제외)', '취소·환불된 온라인 주문이나 아직 수령 전이라 EIS에 없는 금액입니다.'],
            ['POS에 없음', 'POS 입력 없이 카드 리더기로만 받은 결제입니다. EIS에도 없습니다.'],
            ['원샷 처리일', '원샷 판매자 정산에서 정산완료로 처리한 날입니다. 돈을 보낸 날이 아니며, 비어 있으면 아직 처리 전입니다.'],
            ['(송금 없음)', '카드 승인이 없거나 취소된 결제, 현금·계좌이체라서 정산 처리만 되고 보낸 돈이 없는 줄입니다.'],
            ['페이레터 정산', '그 결제의 페이레터 승인·취소 금액입니다. 온라인은 구매번호가 같은 승인, 카드는 같은 날 같은 금액의 승인(여럿이면 가장 가까운 시각)을 붙였습니다.'],
            ['(MM-DD 결제) · (나눠 결제) · (합쳐 결제)', '위 방법으로 못 붙인 카드 주문을 전날·다음 날 같은 금액, 같은 날 승인 2~3건의 합, 같은 날 주문 2~3건의 합으로 한 번 더 맞춘 줄입니다. 금액으로 추정한 것입니다.'],
            ['승인 없음 (현금·이체 추정)', '카드로 찍혔지만 끝까지 카드 승인을 찾지 못한 주문입니다.'],
        ];

        return [
            'smHead' => ['결제종류 · 상태', '건수', '다담다결제 / EIS', '정산완료', '미정산', '페이레터 정산'], 'sm' => $sm, 'smCls' => $smCls,
            'chkHead' => ['구분', '건수', '금액', '내용'], 'chk' => $chk,
            'head' => ['결제일', '결제종류', '상태', $NO("구매번호\n결제번호\n승인번호"), '다담다결제 / EIS', '원샷 처리일', '페이레터 정산'], 'rows' => $rows, 'cls' => $cls,
            'help' => $help,
            'card' => $card, 'fee' => $fee, 'vat' => $vat, 'pay' => (int) round($pay), 'eis' => $eisSum, 'eisN' => $eisN, 'total' => $tA,
            'L' => $L, 'counts' => ['noPg' => [$nNoPg, $aNoPg], 'only' => [$nOnly, $aOnly], 'guess' => [$nDay, $nSplit, $nJoin, $aGuess]],
        ];
    }

    /** 셀 → 글자·숫자 (엑셀용: 객체 셀은 글자만) */
    public static function plain(array $rows): array
    {
        return array_map(fn ($r) => array_map(fn ($v) => is_array($v) ? $v['v'] : (is_float($v) ? (int) round($v) : $v), $r), $rows);
    }

    public static function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
