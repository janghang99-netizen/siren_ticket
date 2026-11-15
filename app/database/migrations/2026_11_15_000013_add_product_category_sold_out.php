<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 메뉴 카테고리 (커피·디저트·음료 …) + 보드에서 바로 켜고 끄는 품절.
 * 품절(sold_out_at)은 재고 수량과 따로 둔다 — 재고 숫자를 지우지 않고 잠깐 막았다가 다시 열 수 있게.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->string('category', 30)->nullable()->after('name');
            $t->timestamp('sold_out_at')->nullable()->after('stock_deduct_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn(['category', 'sold_out_at']);
        });
    }
};
