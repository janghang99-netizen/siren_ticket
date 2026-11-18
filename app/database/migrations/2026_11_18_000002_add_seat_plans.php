<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 공연장 도면 (편집기로 그린 좌석표).
 * 이미지 좌석표(kind=image)는 배치도 위 네모 구역, 도면(kind=plan)은 1000×1000 캔버스에 무대·물체·구역 모양을 그린다.
 * 구역 모양(shape)에서 좌석 위치를 계산한다 — 편집기 미리보기와 팬 좌석 화면이 같은 계산이라 보이는 그대로 팔린다.
 *   사각:   {type:'rect', x, y, w, h, rot, curve, row1:'top'|'bottom', align:'center'|'left'|'right', dir:'ltr'|'rtl'}
 *   부채꼴: {type:'arc', cx, cy, r0, r1, a0, a1, row1:'inner'|'outer', align, dir}   (각도: 0=오른쪽, 시계 방향)
 * 열마다 좌석 수가 다르면 기존 layout [{n, label, seats:[…]}] 에 둔다 (결제 검증 SeatSection::hasSeat 그대로).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_maps', function (Blueprint $t) {
            $t->string('kind', 10)->default('image')->after('id');
            $t->json('plan')->nullable()->after('tiers');          // {stage:{x,y,w,h,rot,label}, objects:[…], labels:[{x,y,text,size}]}
            $t->string('image_path')->nullable()->change();          // 도면은 밑그림 이미지가 없어도 된다
            $t->unsignedInteger('image_w')->default(0)->change();
            $t->unsignedInteger('image_h')->default(0)->change();
        });
        Schema::table('seat_map_sections', function (Blueprint $t) {
            $t->json('shape')->nullable()->after('tier');
            $t->decimal('x', 6, 4)->nullable()->change();
            $t->decimal('y', 6, 4)->nullable()->change();
            $t->decimal('w', 6, 4)->nullable()->change();
            $t->decimal('h', 6, 4)->nullable()->change();
        });
        Schema::table('seat_sections', function (Blueprint $t) {
            $t->json('shape')->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('seat_sections', fn (Blueprint $t) => $t->dropColumn('shape'));
        Schema::table('seat_map_sections', fn (Blueprint $t) => $t->dropColumn('shape'));
        Schema::table('seat_maps', fn (Blueprint $t) => $t->dropColumn(['kind', 'plan']));
    }
};
