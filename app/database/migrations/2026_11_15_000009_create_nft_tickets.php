<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 웹3 기반 — 고객 지갑 + NFT 티켓.
 *  - 지갑은 고객(휴대폰 번호) 단위: NFT 티켓과 마일리지(향후 토큰·코인)가 같은 지갑에 모인다.
 *  - 운영사 보관 지갑(custodial): 팬은 지갑·개인키를 몰라도 된다. 개인키는 체인 연동 시 KMS 등 외부에 둔다.
 *  - NFT 티켓은 양도 불가(소울바운드): 리셀 방지 + 거래되지 않아 가상자산 규제·중국 NFT 거래 금지와 충돌하지 않는다.
 *  - 체인은 미정 → TicketChain 인터페이스. 지금은 local(발행 기록만).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_wallets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('network', 30);
            $t->string('address', 64)->unique();
            $t->string('custody', 20)->default('custodial');
            $t->timestamps();
        });

        Schema::create('ticket_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('network', 30);
            $t->string('contract', 64);
            $t->string('token_id', 78);                    // uint256 (문자열)
            $t->string('owner_address', 64);
            $t->string('status', 10)->default('minted');   // minted / used / burned
            $t->boolean('transferable')->default(false);
            $t->string('metadata_hash', 64);               // 발행 시점 메타데이터 sha256 (개인정보 없음)
            $t->string('mint_tx', 100)->nullable();
            $t->string('burn_tx', 100)->nullable();
            $t->timestamp('minted_at')->nullable();
            $t->timestamp('used_at')->nullable();
            $t->timestamp('burned_at')->nullable();
            $t->timestamps();
            $t->unique(['network', 'contract', 'token_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_tokens');
        Schema::dropIfExists('customer_wallets');
    }
};
