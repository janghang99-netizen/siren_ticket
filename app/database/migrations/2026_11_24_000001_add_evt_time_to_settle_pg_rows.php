<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 페이레터 줄의 결제 시각 (원본 settle_pg_row.evt_time, 2026-11-24 73977926).
 *  승인 줄 = transaction_date, 취소 줄 = cancel_date(없으면 transaction_date), 'YYYY-MM-DD HH:MM:SS'.
 *  보고서에서 다담다 주문과 시간순으로 나란히 놓고, 같은 날 같은 금액 카드 승인 중 가장 가까운 시각을 고르는 데 쓴다.
 *  기존 줄은 비어 있음 → 페이레터에서 같은 날을 다시 불러오면 비어 있는 줄만 채워진다.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settle_pg_rows', 'evt_time')) {
            Schema::table('settle_pg_rows', function (Blueprint $t) {
                $t->string('evt_time', 19)->default('')->after('amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settle_pg_rows', 'evt_time')) {
            Schema::table('settle_pg_rows', fn (Blueprint $t) => $t->dropColumn('evt_time'));
        }
    }
};
