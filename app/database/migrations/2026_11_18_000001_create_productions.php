<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 작품(production) + 회차(event).
 * 예매·좌석·매장 주문은 지금처럼 회차(events) 단위. 작품은 메인·목록·상세에서 회차들을 묶어 보여 주는 단위
 * (포스터·장르·관람 시간·관람 연령·기간).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productions', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('title', 160);
            $t->string('artist', 80)->default('');
            $t->string('genre', 20)->default('concert')->index();   // Production::GENRES
            $t->string('venue', 80)->default('');                   // 표시용 공연장 이름
            $t->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $t->string('poster_path')->nullable();                  // 세로 포스터 (3:4)
            $t->string('banner_path')->nullable();                  // 메인 큰 배너 (가로)
            $t->string('theme_color', 7)->default('#6C4BFF');
            $t->unsignedSmallInteger('runtime_minutes')->nullable();
            $t->string('age_rating', 40)->nullable();               // 예: 8세 이상 관람가
            $t->text('description')->nullable();
            $t->string('badge', 20)->nullable();                    // 예: 단독판매 · 샘플
            $t->boolean('is_featured')->default(false);             // 메인 큰 배너에 올림
            $t->integer('sort')->default(0);
            $t->timestamps();
        });

        Schema::table('events', function (Blueprint $t) {
            $t->foreignId('production_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        // 기존 공연: 아티스트 + 공연장이 같은 회차끼리 작품 하나로 묶는다
        $groups = DB::table('events')->orderBy('starts_at')->get()->groupBy(fn ($e) => $e->artist.'|'.$e->venue);
        foreach ($groups as $events) {
            $first = $events->first();
            $id = DB::table('productions')->insertGetId([
                'slug' => 'tmp-'.Str::random(12),
                'title' => preg_replace('/\s*[·\-]\s*DAY\s*\d+\s*$/iu', '', $first->name),
                'artist' => $first->artist,
                'venue' => $first->venue,
                'venue_id' => $first->venue_id,
                'theme_color' => $first->theme_color ?: '#6C4BFF',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('productions')->where('id', $id)->update(['slug' => (Str::slug($first->artist ?: $first->name) ?: 'show').'-'.$id]);
            DB::table('events')->whereIn('id', $events->pluck('id'))->update(['production_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $t) {
            $t->dropConstrainedForeignId('production_id');
        });
        Schema::dropIfExists('productions');
    }
};
