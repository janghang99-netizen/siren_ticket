<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 실제 좌석 리스트 + 공연별 막은 좌석.
 *  - layout: 구역의 열마다 실제 좌석 번호 [{n:열순번, label:"A", seats:[3,4,…,22]}] — 기획사 좌석 리스트(CSV)로 만든다.
 *    layout 이 없으면 지금처럼 열 × 열당 좌석 격자.
 *  - seat_blocks: 공연별 판매 제외(카메라·시야 방해) / 보류(초대석·기획사 몫) 좌석.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_map_sections', function (Blueprint $t) {
            $t->json('layout')->nullable()->after('seats_per_row');
        });
        Schema::table('seat_sections', function (Blueprint $t) {
            $t->json('layout')->nullable();
            $t->unsignedInteger('blocked')->default(0)->after('sold');   // 판매 제외·보류 좌석 수 (잔여석에서 뺀다)
        });
        Schema::table('tickets', function (Blueprint $t) {
            $t->string('row_label', 10)->nullable()->after('row_no');    // 열 이름이 숫자가 아닐 때 (A열 등)
        });

        Schema::create('seat_blocks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->cascadeOnDelete();
            $t->foreignId('seat_section_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('row_no');
            $t->unsignedSmallInteger('seat_no');
            $t->string('kind', 10);                                       // kill(판매 제외) / hold(보류)
            $t->string('note', 60)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['seat_section_id', 'row_no', 'seat_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_blocks');
        Schema::table('tickets', fn (Blueprint $t) => $t->dropColumn('row_label'));
        Schema::table('seat_sections', fn (Blueprint $t) => $t->dropColumn(['layout', 'blocked']));
        Schema::table('seat_map_sections', fn (Blueprint $t) => $t->dropColumn('layout'));
    }
};
