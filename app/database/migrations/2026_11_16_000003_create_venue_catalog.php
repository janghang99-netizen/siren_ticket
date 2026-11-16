<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 전국 공연장 DB — 공연예술통합전산망(KOPIS) 공연시설 목록을 받아 두고, 운영사가 공연장을 추가할 때 검색해서 불러온다.
 * 시설 하나에 공연장(홀)이 여러 개면 halls 에 담는다 (예: 세종문화회관 대극장 / M씨어터).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_catalog', function (Blueprint $t) {
            $t->id();
            $t->string('source', 10)->default('kopis');     // kopis / seed(기본 목록)
            $t->string('kopis_id', 20)->nullable()->unique(); // KOPIS 공연시설 ID (mt10id)
            $t->string('name', 100);
            $t->string('sido', 20)->nullable();
            $t->string('gugun', 30)->nullable();
            $t->string('address')->nullable();
            $t->unsignedInteger('seats')->nullable();         // 시설 전체 객석 수
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('url')->nullable();
            $t->string('kind', 40)->nullable();               // 시설 특성 (공공·민간 등)
            $t->json('halls')->nullable();                    // [{id,name,seats}]
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
            $t->index(['name']);
            $t->index(['sido', 'gugun']);
        });

        Schema::table('venues', function (Blueprint $t) {
            $t->foreignId('catalog_id')->nullable()->after('id')->constrained('venue_catalog')->nullOnDelete();
            $t->unsignedInteger('seats')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $t) {
            $t->dropConstrainedForeignId('catalog_id');
            $t->dropColumn('seats');
        });
        Schema::dropIfExists('venue_catalog');
    }
};
