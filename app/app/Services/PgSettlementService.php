<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Settlement;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * 카드결제(PG) 기준 정산 — 원본 다담다 admin/inc_settle_pg.php · ajax_settle_pg.php (2026-11-20~21).
 *
 *  실제 송금액 = 기간 안 PG 카드결제(승인 − 취소) − 이용료 8.5% − 부가세(이용료의 10%)
 *  현금·계좌이체는 매장이 직접 받은 돈이라 PG 에 없으므로 자연히 빠진다.
 *  (원본 도봉빵 승인 6/9~11/5: 카드 3,898,960 → 3,534,407 = 실제 송금액. 옛 주문 기준 계산은 3,591,688)
 *
 *  - 페이레터 결제내역 조회: GET {url}/v1.0/payments/transaction/list?client_id&date=YYYYMMDD&date_type=settle
 *    Authorization: PLKEY {조회용 키}. date_type=settle = 그날 일어난 승인·취소 이벤트 모두.
 *  - 키·가맹점 ID 는 config('siren.payletter') ← .env 에서만 읽고, 화면·응답에 키를 내보내지 않는다.
 *  - 주문·결제·정산 데이터는 쓰지 않는다 (settle_pg_* 보고서 전용 테이블만).
 */
class PgSettlementService
{
    /** 설정된 가맹점 (키 포함 — 서버 안에서만 쓴다) */
    public function clients(): array
    {
        return collect(config('siren.payletter.clients', []))
            ->filter(fn ($c) => trim((string) ($c['client_id'] ?? '')) !== '' && trim((string) ($c['search_key'] ?? '')) !== '')
            ->map(fn ($c) => ['client_id' => trim((string) $c['client_id']), 'search_key' => trim((string) $c['search_key']), 'kind' => $c['kind'] ?? 'online', 'store_code' => trim((string) ($c['store_code'] ?? ''))])
            ->values()->all();
    }

    /** 화면용 가맹점 목록 (키는 빼고 ID·구분만) */
    public function publicClients(): array
    {
        return array_map(fn ($c) => ['client_id' => $c['client_id'], 'kind' => $c['kind'], 'store_code' => $c['store_code']], config('siren.payletter.url') ? $this->clients() : []);
    }

    /**
     * 한 업체의 하루치를 페이레터에서 불러와 누적 저장 (원본 ajax_settle_pg.php mode=pl_day). 화면이 날짜마다 1번씩 부른다.
     *
     * @return array{ok:bool, msg?:string, load_id?:int, got?:int, new?:int, partial?:int, other?:int, errors?:array<int,string>}
     */
    public function loadDay(Vendor $vendor, string $day, ?int $loadId, string $label, ?int $userId = null): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return ['ok' => false, 'msg' => '잘못된 날짜예요.'];
        }
        $url = (string) config('siren.payletter.url');
        $clients = $this->clients();
        if ($url === '' || ! $clients) {
            return ['ok' => false, 'msg' => '페이레터 조회 키 설정이 없어요 (.env 의 PAYLETTER_*).'];
        }
        $load = $loadId ? DB::table('settle_pg_loads')->where('id', $loadId)->where('vendor_id', $vendor->id)->first() : null;
        if (! $load) {
            $loadId = DB::table('settle_pg_loads')->insertGetId(['vendor_id' => $vendor->id, 'label' => mb_substr($label, 0, 200), 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        }

        $storeCodes = $vendor->stores()->pluck('code')->all();
        $rows = [];
        $errors = [];
        $partial = 0;
        foreach ($clients as $c) {
            // 매장 단말기 가맹점은 그 매장 업체만 (다른 업체 화면에서는 부르지 않는다)
            if ($c['kind'] === 'terminal' && ! in_array($c['store_code'], $storeCodes, true)) {
                continue;
            }
            [$ok, $list] = $this->fetchDay($url, $c['client_id'], $c['search_key'], str_replace('-', '', $day));
            if (! $ok) {
                $errors[] = $c['client_id'].': '.$list;

                continue;
            }
            foreach ($list as $x) {
                $row = is_array($x) ? self::rowFromApi($x, $c['kind']) : null;
                if ($row === null) {
                    $partial++;

                    continue;
                }
                $rows[] = $row;
            }
        }

        // 온라인 가맹점은 사이트 전체 공용 → 이 업체 매장의 주문번호인 줄만 남긴다. 단말기는 그 매장 것 그대로.
        $onlineNos = array_values(array_unique(array_filter(array_map(fn ($r) => $r['kind'] === 'online' ? $r['order_no'] : '', $rows))));
        $mine = $onlineNos ? array_flip(Order::whereIn('store_id', $vendor->stores()->pluck('id'))->whereIn('order_no', $onlineNos)->pluck('order_no')->all()) : [];
        $other = 0;
        $rows = array_values(array_filter($rows, function ($r) use ($mine, &$other) {
            if ($r['kind'] === 'online' && ! isset($mine[$r['order_no']])) {
                $other++;

                return false;
            }

            return true;
        }));

        $new = $this->insertRows($vendor->id, $loadId, $rows);
        $minDay = collect($rows)->pluck('approved_on')->push($day)->min();
        $load = DB::table('settle_pg_loads')->find($loadId);
        DB::table('settle_pg_loads')->where('id', $loadId)->update([
            'rows_total' => $load->rows_total + count($rows),
            'rows_new' => $load->rows_new + $new,
            'approved_from' => $load->approved_from && $load->approved_from <= $minDay ? $load->approved_from : $minDay,
            'approved_to' => $load->approved_to && $load->approved_to >= $day ? $load->approved_to : $day,
            'updated_at' => now(),
        ]);

        return ['ok' => true, 'load_id' => $loadId, 'got' => count($rows), 'new' => $new, 'partial' => $partial, 'other' => $other, 'errors' => $errors];
    }

    /** @return array{0:bool,1:array|string} 한 가맹점 하루치 승인·취소 목록 */
    private function fetchDay(string $url, string $clientId, string $key, string $ymd): array
    {
        try {
            $res = Http::connectTimeout(5)->timeout(20)
                ->withHeaders(['Authorization' => 'PLKEY '.$key])
                ->get($url.'/v1.0/payments/transaction/list', ['client_id' => $clientId, 'date' => $ymd, 'date_type' => 'settle']);
        } catch (ConnectionException $e) {
            return [false, '통신 오류'];
        }
        $j = $res->json();
        if ($res->status() !== 200 || ! is_array($j) || ! isset($j['list']) || ! is_array($j['list'])) {
            $m = is_array($j) && isset($j['message']) ? (string) $j['message'] : mb_substr($res->body(), 0, 120);

            return [false, 'HTTP '.$res->status().' '.$m];
        }

        return [true, $j['list']];
    }

    /**
     * API 한 줄 → 저장 줄. status_code 0=승인(+금액), 1=취소(−금액, 승인일 = 원 결제일), 2=부분취소는 금액 의미가 확인되지 않아 null(건너뜀).
     */
    public static function rowFromApi(array $x, string $kind): ?array
    {
        $st = (string) ($x['status_code'] ?? '');
        if ($st !== '0' && $st !== '1') {
            return null;
        }
        $amount = (int) round((float) ($x['amount'] ?? 0));

        return [
            'tid' => (string) ($x['tid'] ?? ''),
            'approval_no' => (string) ($x['cid'] ?? ''),
            'approved_on' => substr((string) ($x['transaction_date'] ?? ''), 0, 10),
            'kind' => $kind,
            'pg_code' => (string) ($x['pgcode'] ?? ''),
            'order_no' => (string) ($x['order_no'] ?? ''),
            'status' => $st === '0' ? '승인' : '취소',
            'amount' => $st === '0' ? $amount : -$amount,
        ];
    }

    /** 중복 없이 저장 (같은 업체 안 ukey 고유). 반환 = 새로 저장된 줄 수 */
    public function insertRows(int $vendorId, ?int $loadId, array $rows): int
    {
        $new = 0;
        foreach ($rows as $r) {
            $day = substr((string) ($r['approved_on'] ?? ''), 0, 10);
            $amount = (int) ($r['amount'] ?? 0);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $amount === 0) {
                continue;
            }
            $tid = trim((string) ($r['tid'] ?? ''));
            $status = (string) ($r['status'] ?? '');
            $ukey = $tid !== '' ? "{$tid}|{$status}|{$amount}" : trim((string) ($r['approval_no'] ?? ''))."|{$day}|{$status}|{$amount}";
            $new += DB::table('settle_pg_rows')->insertOrIgnore([
                'load_id' => $loadId, 'vendor_id' => $vendorId, 'ukey' => mb_substr($ukey, 0, 190),
                'tid' => mb_substr($tid, 0, 80), 'approval_no' => mb_substr((string) ($r['approval_no'] ?? ''), 0, 60),
                'approved_on' => $day, 'settled_on' => $r['settled_on'] ?? null, 'kind' => ($r['kind'] ?? '') === 'terminal' ? 'terminal' : 'online',
                'pg_code' => mb_substr((string) ($r['pg_code'] ?? ''), 0, 40), 'order_no' => mb_substr((string) ($r['order_no'] ?? ''), 0, 80),
                'status' => mb_substr($status, 0, 10), 'amount' => $amount, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $new;
    }

    /** 불러오기 기록 삭제 — 그때 새로 저장된 줄도 지운다 (겹쳐 저장 안 된 줄은 먼저 저장한 쪽에 남음) */
    public function deleteLoad(Vendor $vendor, int $loadId): int
    {
        $n = DB::table('settle_pg_rows')->where('vendor_id', $vendor->id)->where('load_id', $loadId)->delete();
        DB::table('settle_pg_loads')->where('vendor_id', $vendor->id)->where('id', $loadId)->delete();

        return $n;
    }

    /** 페이레터 내역을 어디까지 불러왔는지 ('' = 불러온 적 없음) */
    public function loadedTo(Vendor $vendor): string
    {
        return substr((string) DB::table('settle_pg_loads')->where('vendor_id', $vendor->id)->max('approved_to'), 0, 10);
    }

    /**
     * 승인일 $from~$to 의 카드 합계와 정산금액 (원본 sp_card_settle). 이 업체가 페이레터를 불러온 적이 없으면 ok=false → 화면은 기존 계산 그대로.
     *
     * @return array{ok:bool, from:string, to:string, n:int, card:int, fee:int, vat:int, pay:int, loaded_to:string, short:bool}
     */
    public function cardSettle(Vendor $vendor, string $from, string $to, ?float $rate = null): array
    {
        $o = ['ok' => false, 'from' => $from, 'to' => $to, 'n' => 0, 'card' => 0, 'fee' => 0, 'vat' => 0, 'pay' => 0, 'loaded_to' => '', 'short' => false];
        if (! self::isYmd($from) || ! self::isYmd($to)) {
            return $o;
        }
        $o['loaded_to'] = $this->loadedTo($vendor);
        if ($o['loaded_to'] === '') {
            return $o;
        }
        $q = DB::table('settle_pg_rows')->where('vendor_id', $vendor->id)->whereBetween('approved_on', [$from, $to]);
        $card = (int) (clone $q)->sum('amount');
        [$fee, $vat, $pay] = self::split($card, $rate);

        return ['ok' => true, 'n' => (int) $q->count(), 'card' => $card, 'fee' => (int) round($fee), 'vat' => (int) round($vat),
            'pay' => (int) round($pay), 'short' => $o['loaded_to'] < $to] + $o;
    }

    /** @return array{0:float,1:float,2:float} 이용료·부가세·정산금액 (반올림 전 — 지급액은 round(card − fee − vat)) */
    public static function split(int|float $card, ?float $rate = null): array
    {
        $fee = $card * ($rate ?? (float) config('siren.card_settle_rate')) / 100;
        $vat = $fee * (float) config('siren.card_settle_vat') / 100;

        return [$fee, $vat, $card - $fee - $vat];
    }

    /**
     * 정산 대기(수령완료) 묶음의 승인일 기간 (원본 sp_pending_window).
     *  시작 = 정산완료 주문의 마지막 주문일 + 1 (이전 정산 없으면 첫 주문일과 PG 첫 승인일 중 빠른 날), 부분정산 시작일이 있으면 그 날
     *  끝 = 이번 묶음 마지막 주문일
     *
     * @return array{0:string,1:string} 주문이 없으면 ['', '']
     */
    public function pendingWindow(Vendor $vendor, ?string $start = null): array
    {
        $storeIds = $vendor->stores()->pluck('id');
        $waiting = Order::whereIn('store_id', $storeIds)->where('status', OrderStatus::PickedUp->value)->whereNull('settlement_id');
        $last = (clone $waiting)->max('created_at');
        if (! $last) {
            return ['', ''];
        }
        $to = Carbon::parse($last)->toDateString();
        $prev = Order::whereIn('store_id', $storeIds)->where('status', OrderStatus::Settled->value)->max('created_at');
        $from = $prev ? Carbon::parse($prev)->addDay()->toDateString()
            : $this->firstPgDay($vendor, Carbon::parse((clone $waiting)->min('created_at'))->toDateString());
        if ($start && self::isYmd($start)) {
            $from = $start;
        }

        return [min($from, $to), $to];
    }

    /** 정산 완료 묶음(정산서 1장)의 승인일 기간 (원본 sp_bundle_window). 이전 정산서와 겹치거나 비지 않게 주문일로 잇는다 */
    public function bundleWindow(Settlement $settlement): array
    {
        $orders = Order::where('settlement_id', $settlement->id);
        $last = (clone $orders)->max('created_at');
        if (! $last) {
            return ['', ''];
        }
        $to = Carbon::parse($last)->toDateString();
        $earlier = Settlement::where('vendor_id', $settlement->vendor_id)->where('id', '<', $settlement->id)->pluck('id');
        $prev = $earlier->isEmpty() ? null : Order::whereIn('settlement_id', $earlier)->max('created_at');
        $from = $prev ? Carbon::parse($prev)->addDay()->toDateString()
            : $this->firstPgDay($settlement->vendor, Carbon::parse((clone $orders)->min('created_at'))->toDateString());

        return [min($from, $to), $to];
    }

    /** 첫 정산: 첫 주문일과 저장된 PG 첫 승인일 중 빠른 날 (주문 없는 날의 카드결제도 포함) */
    private function firstPgDay(Vendor $vendor, string $from): string
    {
        $first = substr((string) DB::table('settle_pg_rows')->where('vendor_id', $vendor->id)->min('approved_on'), 0, 10);

        return self::isYmd($first) && $first < $from ? $first : $from;
    }

    /** 보고서·화면에 쓸 매장 이름: 업체 상호(끝 괄호 제거, 예 "도봉빵 (Do BON PAIN)" → "도봉빵") → 대표 매장 이름 → 업체 번호 */
    public static function storeName(Vendor $vendor): string
    {
        $name = trim((string) preg_replace('/\s*\([^()]*\)\s*$/u', '', trim((string) $vendor->name)));
        if ($name !== '') {
            return $name;
        }
        $store = trim((string) Store::where('vendor_id', $vendor->id)->orderBy('id')->value('name'));

        return $store !== '' ? $store : '업체 #'.$vendor->id;
    }

    public static function isYmd(?string $d): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d);
    }
}
