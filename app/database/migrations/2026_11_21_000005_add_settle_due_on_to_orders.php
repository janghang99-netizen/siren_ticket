<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 주문별 정산예정일 (원본 다담다 auction_jangproduct.jungsan_schedule_date).
 *  원본에서 옮겨 온 주문은 원본에 저장된 예정일을 그대로 쓴다 — 원본 설정 "상품판매 정산예정일"이 2026-11-21 09:49 에 5일 → 7일로 바뀌었고,
 *  그 전에 저장된 주문은 주문일 + 5일. 비어 있으면(새 시스템 주문) 지금 설정값(settle_due_days)으로 계산한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->date('settle_due_on')->nullable()->after('settled_at');
        });

        // 이미 옮겨 온 주문 = 모두 2026-11-17 에 읽은 주문(5일 기준 시절) → 주문일 + 5일
        DB::table('orders')->whereNotNull('imported_from')->orderBy('id')->select(['id', 'created_at'])->chunkById(500, function ($rows) {
            foreach ($rows as $r) {
                DB::table('orders')->where('id', $r->id)->update(['settle_due_on' => \Illuminate\Support\Carbon::parse($r->created_at)->addDays(5)->toDateString()]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn('settle_due_on');
        });
    }
};
