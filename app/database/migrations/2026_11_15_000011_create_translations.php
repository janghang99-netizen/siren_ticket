<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 번역 — 원본 다담다 방식 (inc/lib_translate.php · html/uasns_header2.html).
 *  - 화면 전체: 구글 번역 위젯 (en / ja / zh-CN)
 *  - 상품명: 구글 대신 Claude API 로 미리 번역해 둔 사전 (product_translations)
 *  - 교정 사전: 구글이 틀리게 번역한 말을 [잘못된 번역 → 올바른 번역] 으로 고친다
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_translations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('lang', 8);                 // en / ja / zh
            $t->string('src_name', 250);           // 번역 당시 한국어 상품명 — 바뀌면 다시 번역 대상
            $t->string('trans_name', 250);
            $t->timestamps();
            $t->unique(['product_id', 'lang']);
            $t->index('lang');
        });

        Schema::create('translation_fixes', function (Blueprint $t) {
            $t->id();
            $t->string('lang', 8);
            $t->string('wrong', 200);
            $t->string('right', 200);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['lang', 'wrong']);
        });

        // 원본 다담다 FIX_DICT 기본값
        DB::table('translation_fixes')->insert(['lang' => 'en', 'wrong' => 'Organic Gasoline', 'right' => 'Organic Gassosa', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_fixes');
        Schema::dropIfExists('product_translations');
    }
};
