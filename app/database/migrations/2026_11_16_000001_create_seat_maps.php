<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 공연장 좌석표 — 기획사가 준 좌석 배치도 이미지 위에 구역(박스)을 올리고, 구역마다 열 × 열당 좌석으로 객석을 만든다.
 * 같은 공연장도 무대 배치(가로·세로)가 달라질 수 있어 좌석표는 공연장이 아니라 "배치" 단위로 만들고, 공연이 하나를 고른다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_maps', function (Blueprint $t) {
            $t->id();
            $t->string('name');                         // 예: 고양종합운동장 · 가로 무대
            $t->string('venue');
            $t->string('image_path');
            $t->unsignedInteger('image_w');
            $t->unsignedInteger('image_h');
            $t->json('tiers');                          // [{key,name,color,price,seats}]
            $t->string('read_by', 20)->default('manual'); // manual / ai
            $t->timestamps();
        });

        Schema::create('seat_map_sections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seat_map_id')->constrained()->cascadeOnDelete();
            $t->string('code', 10);
            $t->string('tier', 10);                     // seat_maps.tiers[].key
            $t->decimal('x', 6, 4);                     // 이미지 너비·높이 대비 0~1
            $t->decimal('y', 6, 4);
            $t->decimal('w', 6, 4);
            $t->decimal('h', 6, 4);
            $t->unsignedSmallInteger('rows');
            $t->unsignedSmallInteger('seats_per_row');
            $t->unsignedInteger('sort')->default(0);
            $t->timestamps();
            $t->unique(['seat_map_id', 'code']);
        });

        Schema::table('events', function (Blueprint $t) {
            $t->foreignId('seat_map_id')->nullable()->after('venue')->constrained()->nullOnDelete();
        });

        // 공연 구역에 좌석표 위치·색·좌석 격자를 복사해 둔다 (좌석표를 나중에 고쳐도 판매 중인 공연은 그대로)
        Schema::table('seat_sections', function (Blueprint $t) {
            $t->string('color', 7)->nullable()->after('tier');
            $t->decimal('x', 6, 4)->nullable();
            $t->decimal('y', 6, 4)->nullable();
            $t->decimal('w', 6, 4)->nullable();
            $t->decimal('h', 6, 4)->nullable();
            $t->unsignedSmallInteger('rows')->nullable();
            $t->unsignedSmallInteger('seats_per_row')->nullable();
        });

        // 팔린 좌석 — (구역, 열, 번) 유일 키로 같은 좌석 이중 판매를 DB 가 막는다. 취소하면 행을 지워 다시 판매.
        Schema::create('taken_seats', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->cascadeOnDelete();
            $t->foreignId('seat_section_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('row_no');
            $t->unsignedSmallInteger('seat_no');
            $t->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['seat_section_id', 'row_no', 'seat_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taken_seats');
        Schema::table('seat_sections', function (Blueprint $t) {
            $t->dropColumn(['color', 'x', 'y', 'w', 'h', 'rows', 'seats_per_row']);
        });
        Schema::table('events', function (Blueprint $t) {
            $t->dropConstrainedForeignId('seat_map_id');
        });
        Schema::dropIfExists('seat_map_sections');
        Schema::dropIfExists('seat_maps');
    }
};
