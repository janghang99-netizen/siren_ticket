<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 팬 회원 (티켓 예매 전용 계정). 운영사·업체 계정(users)과 테이블·로그인 세션을 완전히 나눈다.
 *  - 해외 회원(특히 중국): 국가번호 휴대폰 + 이메일 인증 (중국 번호는 국제 문자가 막히는 경우가 많아 이메일 인증을 함께 연다)
 *  - 해킹 대비: 로그인 실패 잠금, 새 기기 추가 인증, 선택 2단계 인증(OTP 앱), 로그인 기록, 비밀번호 변경 시 다른 세션 끊기
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->nullable()->unique()->constrained()->nullOnDelete();   // 마일리지·쿠폰·스탬프
            $t->string('name', 50);
            $t->string('country', 2)->default('KR');
            $t->string('phone', 20)->unique();             // 한국: 01012345678 / 해외: 국가번호+번호 (8613800138000)
            $t->string('email')->nullable()->unique();
            $t->string('password');
            $t->string('locale', 10)->default('ko');
            $t->timestamp('phone_verified_at')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->text('two_factor_secret')->nullable();      // 암호화 저장
            $t->text('two_factor_recovery_codes')->nullable();
            $t->timestamp('two_factor_confirmed_at')->nullable();
            $t->unsignedSmallInteger('failed_logins')->default(0);
            $t->timestamp('locked_until')->nullable();
            $t->unsignedInteger('session_version')->default(1);   // 비밀번호 바꾸면 올려서 다른 기기 세션을 끊는다
            $t->timestamp('password_changed_at')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->string('last_login_ip', 45)->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('member_devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();        // 쿠키 값의 sha256 (원문은 저장하지 않음)
            $t->string('label', 120)->nullable();          // 브라우저·OS 요약
            $t->string('ip', 45)->nullable();
            $t->timestamp('trusted_until');
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });

        Schema::create('member_login_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('identifier_hash', 64)->nullable(); // 없는 계정 시도도 묶어 보기 위해 (원문 미저장)
            $t->string('result', 20);                      // success / bad_password / locked / step_up / two_factor / two_factor_failed / unknown
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 200)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['ip', 'created_at']);
            $t->index(['member_id', 'created_at']);
        });

        Schema::create('one_time_codes', function (Blueprint $t) {
            $t->id();
            $t->string('purpose', 20);                     // signup / login / reset
            $t->string('channel', 10);                     // sms / email
            $t->string('destination', 190);
            $t->string('code_hash', 64);
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamps();
            $t->index(['destination', 'purpose']);
        });

        Schema::table('tickets', function (Blueprint $t) {
            $t->foreignId('member_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $t) => $t->dropConstrainedForeignId('member_id'));
        foreach (['one_time_codes', 'member_login_events', 'member_devices', 'members'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
