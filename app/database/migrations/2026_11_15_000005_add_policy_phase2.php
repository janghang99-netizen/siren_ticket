<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 오픈마켓 정책 2단계: D2 취소 요청 · F4 공연 취소 · G1 감사 로그 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->timestamp('cancel_requested_at')->nullable()->after('cancel_reason');
            $t->string('cancel_request_reason', 200)->nullable()->after('cancel_requested_at');
            $t->string('cancel_request_result', 10)->nullable()->after('cancel_request_reason');  // approved / rejected
            $t->string('cancel_request_response', 200)->nullable()->after('cancel_request_result');
            $t->timestamp('cancel_responded_at')->nullable()->after('cancel_request_response');
        });

        Schema::table('events', function (Blueprint $t) {
            $t->string('status', 20)->default('scheduled')->after('artist');   // scheduled / cancelled
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason')->nullable();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 40);                 // vendor.approved, product.blocked, order.refunded ...
            $t->string('subject_type', 40)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('summary');
            $t->json('data')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['subject_type', 'subject_id']);
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::table('events', fn (Blueprint $t) => $t->dropColumn(['status', 'cancelled_at', 'cancel_reason']));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn([
            'cancel_requested_at', 'cancel_request_reason', 'cancel_request_result', 'cancel_request_response', 'cancel_responded_at',
        ]));
    }
};
