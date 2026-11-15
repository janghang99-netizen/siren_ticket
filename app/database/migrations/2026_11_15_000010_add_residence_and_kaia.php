<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 거주 국가 + 카이아 연동 준비.
 *  - residence_country: 휴대폰 국가와 별개. 체인 기록·향후 코인 기능은 국적·접속 위치가 아니라 거주 국가로 정한다
 *    (예: 한국에 사는 중국인 = KR 거주, 한국에 잠시 온 중국 관광객 = CN 거주).
 *  - customer_wallets.secret: 운영사 보관 지갑 개인키 (앱 키로 암호화). 운영 단계에서는 KMS·HSM 으로 옮긴다.
 *  - ticket_tokens.status 에 pending(체인 전송 실패·대기) 추가, 입장 기록 트랜잭션 used_tx.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $t) {
            $t->string('residence_country', 2)->default('KR')->after('country');
        });
        DB::table('members')->update(['residence_country' => DB::raw('country')]);

        Schema::table('customer_wallets', function (Blueprint $t) {
            $t->text('secret')->nullable()->after('address');
        });

        Schema::table('ticket_tokens', function (Blueprint $t) {
            $t->string('used_tx', 100)->nullable()->after('burn_tx');
            $t->string('last_error', 250)->nullable()->after('used_tx');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_tokens', fn (Blueprint $t) => $t->dropColumn(['used_tx', 'last_error']));
        Schema::table('customer_wallets', fn (Blueprint $t) => $t->dropColumn('secret'));
        Schema::table('members', fn (Blueprint $t) => $t->dropColumn('residence_country'));
    }
};
