<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 매장 안내 정보 (원본 다담다 매장 화면: 지역 · 주소 · 영업시간 · 휴무) — 상시 매장 주문 화면에 보인다 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $t) {
            $t->string('area', 40)->nullable()->after('name');        // 예: 쌍문동
            $t->string('address', 160)->nullable()->after('area');
            $t->string('hours', 120)->nullable()->after('address');   // 예: 평일 11:00~20:00
            $t->string('holidays', 120)->nullable()->after('hours');  // 예: 매주 토·일 / 공휴일
        });
    }

    public function down(): void
    {
        Schema::table('stores', fn (Blueprint $t) => $t->dropColumn(['area', 'address', 'hours', 'holidays']));
    }
};
