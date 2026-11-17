<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 원본에서 옮겨 온 생산 기록 번호 (예: dadamda:647) — 다시 가져와도 같은 기록을 두 번 넣지 않는다 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_productions', function (Blueprint $t) {
            $t->string('legacy_ref', 40)->nullable()->unique()->after('store_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_productions', function (Blueprint $t) {
            $t->dropUnique(['legacy_ref']);
            $t->dropColumn('legacy_ref');
        });
    }
};
