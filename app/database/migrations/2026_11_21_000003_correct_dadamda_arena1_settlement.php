<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 원본 다담다 arena1(도봉빵) 정산 데이터 보정 — 11-17 에 읽어 온 뒤 원본에서 바뀐 두 가지를 똑같이 반영한다.
 *  (원본 "다담다 정산 데이터 불일치" 세션 확인, 2026-11-21)
 *  a. 2026-11-20 원본 관리자가 3/26 테스트 주문 3건(7,500원)을 삭제 → 여기서도 삭제 (지우기 전에 storage/app/backups 에 JSON 보관)
 *  b. 2026-11-21 11/12 원샷 정산에 함께 묶였지만 송금되지 않은 주문일 11/06~11/10 8건(23줄, 133,280원)을 정산완료(6) → 수령완료(5)
 *  → 2026-11-12 00:30:15 정산서 = 332건 · 주문일 6/19~11/05 · 매출 3,928,000 · 정산금(주문 기준) 3,591,688 (원본 저장값과 같음).
 *    실제 송금액 3,534,407 은 카드결제 기준 표시(승인일 6/09~11/05)로 보인다.
 *  정산서가 없거나 이미 보정됐으면(주문 343건이 아니면) 아무것도 하지 않는다.
 */
return new class extends Migration
{
    private const REF = 'dadamda:arena1:2026-11-12 00:30:15';

    private const REVERTED = ['arena1-1793944852-2584', 'arena1-1793945595-9611', 'arena1-1793946322-3745', 'arena1-1793949195-3643',
        'arena1-1793951093-9079', 'arena1-1794218629-8214', 'arena1-1794298111-5406', 'arena1-1794298145-2945'];

    public function up(): void
    {
        $s = DB::table('settlements')->where('legacy_ref', self::REF)->first();
        if (! $s || (int) $s->order_count !== 343) {
            return;
        }
        $reverted = DB::table('orders')->where('settlement_id', $s->id)->whereIn('order_no', self::REVERTED)->get(['id', 'total_amount']);
        $test = DB::table('orders')->where('settlement_id', $s->id)->where('created_at', '<', '2026-06-01 00:00:00')->get();
        if ($reverted->count() !== 8 || (int) $reverted->sum('total_amount') !== 133280 || $test->count() !== 3 || (int) $test->sum('total_amount') !== 7500) {
            throw new RuntimeException('도봉빵 정산 보정: 대상 주문이 원본 확인값과 달라 멈춰요 (되돌릴 8건 133,280원 · 테스트 3건 7,500원).');
        }

        Storage::disk('local')->put('backups/dadamda_arena1_test_orders_deleted_20261121.json', json_encode([
            'reason' => '원본 다담다에서 2026-11-20 삭제된 3/26 테스트 주문',
            'orders' => $test,
            'items' => DB::table('order_items')->whereIn('order_id', $test->pluck('id'))->get(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        DB::transaction(function () use ($s, $reverted, $test) {
            DB::table('orders')->whereIn('id', $reverted->pluck('id'))->update(['status' => 5, 'settlement_id' => null, 'settled_at' => null]);
            DB::table('orders')->whereIn('id', $test->pluck('id'))->delete();   // 주문 상품 줄 등은 함께 지워진다(cascade)

            $left = DB::table('orders')->where('settlement_id', $s->id);
            $gross = (int) (clone $left)->sum('total_amount');
            $fee = $gross - 3591688;                                            // 원본 저장 정산금(주문 기준) 3,591,688 → 수수료(부가세 포함)
            $commission = (int) round($fee / 1.1);
            DB::table('settlements')->where('id', $s->id)->update([
                'order_count' => (clone $left)->count(),
                'gross_amount' => $gross,
                'platform_benefit_amount' => (int) (clone $left)->sum('mileage_used'),
                'commission_amount' => $commission,
                'commission_vat' => $fee - $commission,
                'payout_amount' => 3591688,
                'period_start' => substr((string) (clone $left)->min('created_at'), 0, 10),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // 데이터 보정이라 자동으로 되돌리지 않는다 (지운 테스트 주문은 storage/app/backups 의 JSON 참고)
    }
};
