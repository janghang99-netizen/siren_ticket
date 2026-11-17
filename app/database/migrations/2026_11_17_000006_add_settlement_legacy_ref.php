<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 원본에서 옮겨 온 정산서 표시 (예: dadamda:arena1:2026-11-12 00:30:15) — 같은 정산을 두 번 넣지 않는다 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $t) {
            $t->string('legacy_ref', 60)->nullable()->unique()->after('vendor_id');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $t) {
            $t->dropUnique(['legacy_ref']);
            $t->dropColumn('legacy_ref');
        });
    }
};
