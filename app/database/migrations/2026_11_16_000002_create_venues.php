<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 공연장 목록 — 공연장 하나에 좌석표(무대 배치별) 여러 개, 공연은 공연장과 좌석표를 고른다.
 * 좌석표에는 "보여줄 영역"(배치도만, 범례·요약표·제목 제외)을 0~1 비율로 둔다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60)->unique();
            $t->string('address')->nullable();
            $t->string('note')->nullable();
            $t->timestamps();
        });

        Schema::table('seat_maps', function (Blueprint $t) {
            $t->foreignId('venue_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $t->decimal('crop_x', 6, 4)->default(0);
            $t->decimal('crop_y', 6, 4)->default(0);
            $t->decimal('crop_w', 6, 4)->default(1);
            $t->decimal('crop_h', 6, 4)->default(1);
        });

        Schema::table('events', function (Blueprint $t) {
            $t->foreignId('venue_id')->nullable()->after('venue')->constrained()->nullOnDelete();
        });

        // 지금까지 글자로만 적던 공연장 이름을 공연장 목록으로 옮긴다
        $names = DB::table('events')->pluck('venue')->merge(DB::table('seat_maps')->pluck('venue'))
            ->map(fn ($n) => trim((string) $n))->filter()->unique();
        foreach ($names as $name) {
            $id = DB::table('venues')->insertGetId(['name' => mb_substr($name, 0, 60), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('events')->where('venue', $name)->update(['venue_id' => $id]);
            DB::table('seat_maps')->where('venue', $name)->update(['venue_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $t) {
            $t->dropConstrainedForeignId('venue_id');
        });
        Schema::table('seat_maps', function (Blueprint $t) {
            $t->dropConstrainedForeignId('venue_id');
            $t->dropColumn(['crop_x', 'crop_y', 'crop_w', 'crop_h']);
        });
        Schema::dropIfExists('venues');
    }
};
