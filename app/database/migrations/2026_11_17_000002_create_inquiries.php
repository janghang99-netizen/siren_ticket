<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 1:1 문의 (원본 happy_inquiry) — 고객이 남기고 운영사가 답한다. 답이 달리면 문자로 알린다. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();   // 휴대폰 = 고객
            $t->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $t->string('category', 20);                                     // order · store · account · etc
            $t->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $t->string('order_no', 40)->nullable();
            $t->string('title', 120);
            $t->text('body');
            $t->string('status', 20)->default('open');                      // open(답변 대기) · answered
            $t->text('answer')->nullable();
            $t->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('answered_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
