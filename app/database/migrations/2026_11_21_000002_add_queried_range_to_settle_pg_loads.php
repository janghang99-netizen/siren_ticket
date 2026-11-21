<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 페이레터 불러오기 기록에 "조회한 결제일 범위"를 따로 남긴다 (원본 settle_pg_upload.appr_from/appr_to).
 *  페이레터 카드 결제는 며칠 늦게 조회에 잡히므로, 승인일 d 는 d 를 포함해 d+10일 이후에 조회한 기록이 있어야 확정으로 본다.
 *  approved_from/to 는 저장된 줄의 승인일까지 넓어질 수 있어(취소 줄 등) 확정 판정에는 조회 범위를 쓴다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settle_pg_loads', function (Blueprint $t) {
            $t->date('queried_from')->nullable()->after('approved_to');
            $t->date('queried_to')->nullable()->after('queried_from');
        });
    }

    public function down(): void
    {
        Schema::table('settle_pg_loads', function (Blueprint $t) {
            $t->dropColumn(['queried_from', 'queried_to']);
        });
    }
};
