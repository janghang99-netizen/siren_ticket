<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 이관한 상품의 원본 번호 (예: dadamda:534) — 다시 가져와도 같은 상품을 덮어쓰고 새로 만들지 않는다 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->string('legacy_ref', 40)->nullable()->unique()->after('store_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropUnique(['legacy_ref']);
            $t->dropColumn('legacy_ref');
        });
    }
};
