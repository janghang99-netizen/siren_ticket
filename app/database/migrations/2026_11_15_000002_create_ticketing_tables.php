<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $t) {
            $t->string('slug')->nullable()->unique()->after('id');
            $t->string('artist')->default('')->after('name');
            $t->string('venue')->default('서울아레나')->after('artist');
            $t->dateTime('doors_at')->nullable()->after('starts_at');
            $t->dateTime('sales_open_at')->nullable()->after('doors_at');
            $t->string('theme_color', 7)->default('#6C4BFF');   // 팬덤 공식 컬러
            $t->unsignedTinyInteger('max_per_buyer')->default(4);
        });

        // 좌석 구역 — 원형 보울 좌석도의 호(arc) 하나 = 구역 하나
        Schema::create('seat_sections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->cascadeOnDelete();
            $t->string('code', 10);                     // F1, 101 ...
            $t->string('name');
            $t->string('tier', 10);                     // VIP / R / S
            $t->unsignedInteger('price');
            $t->unsignedInteger('capacity');
            $t->unsignedInteger('sold')->default(0);
            $t->unsignedTinyInteger('ring');            // 0 = 플로어, 1 = 1층, 2 = 2층
            $t->smallInteger('start_deg');              // 좌석도 호 시작각 (0 = 무대 반대편 정중앙, 시계방향)
            $t->smallInteger('end_deg');
            $t->timestamps();
            $t->unique(['event_id', 'code']);
        });

        Schema::create('tickets', function (Blueprint $t) {
            $t->id();
            $t->string('ticket_no', 32)->unique();
            $t->foreignId('event_id')->constrained();
            $t->foreignId('seat_section_id')->constrained();
            $t->unsignedInteger('row_no');
            $t->unsignedInteger('seat_no');
            $t->string('holder_name');
            $t->string('holder_phone', 20)->index();
            $t->unsignedInteger('price');
            $t->enum('status', ['paid', 'cancelled', 'used'])->default('paid');
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('checked_in_at')->nullable();
            $t->timestamps();
        });

        // 티켓 = 매장 주문 패스: 주문에 좌석 정보를 연결
        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('ticket_id')->nullable()->after('capacity_slot_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('ticket_id');
        });
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('seat_sections');
        Schema::table('events', function (Blueprint $t) {
            $t->dropUnique(['slug']);
            $t->dropColumn(['slug', 'artist', 'venue', 'doors_at', 'sales_open_at', 'theme_color', 'max_per_buyer']);
        });
    }
};
