<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SNS 로그인 연결 (네이버·카카오·구글) — 한 회원에 여러 SNS 를 연결할 수 있다 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_social_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();
            $t->string('provider', 20);            // naver · kakao · google
            $t->string('provider_user_id', 191);   // SNS 고유 ID
            $t->string('email')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'provider_user_id']);
            $t->unique(['member_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_social_accounts');
    }
};
