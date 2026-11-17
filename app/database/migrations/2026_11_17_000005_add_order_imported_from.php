<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 원본 시스템에서 옮겨 온 주문 표시 (예: dadamda).
 * 옮겨 온 주문은 원본에서 이미 정산·환불 처리가 끝났거나 끝날 것이라 새 자동 정산에 넣지 않는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->string('imported_from', 20)->nullable()->after('channel')->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex(['imported_from']);
            $t->dropColumn('imported_from');
        });
    }
};
