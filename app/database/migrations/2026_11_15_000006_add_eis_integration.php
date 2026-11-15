<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 원본 다담다 EIS 연동 이전 — 업체(입점업체) 단위로 관리한다.
 * 원본 seller_webhook(seller_id, webhook_url, production_url, webhook_secret, is_active) ↔ vendor_integrations
 * 한 업체의 모든 매장 주문·생산이 그 업체의 EIS 로 간다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_integrations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('eis_code', 40)->unique();       // EIS 쪽 seller_id (pull API 요청 키)
            $t->string('order_url')->default('');      // 주문 webhook (order.completed)
            $t->string('production_url')->default(''); // 생산 webhook (production.*) — 원본처럼 주문과 분리
            $t->string('secret', 100);                 // HMAC 공유키
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::dropIfExists('store_webhooks');   // 매장 단위 설정 폐기 (업체 단위로 이전)

        // 현장 카드 단말기 (매장별, 준비 단계) — 승인번호로 현장카드 매출을 주문·정산과 대사한다.
        // 원본 known_issues: "KSNET 단말기 자료엔 매장/상품 정보가 없어 다담다 주문과 매칭 불가" → 주문에 TID·승인번호를 남겨 해결
        Schema::create('store_terminals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->string('provider', 30);                // KSNET / NICE / KICC / KIS ... (밴사·단말기 업체)
            $t->string('tid', 40)->unique();           // 단말기 ID
            $t->string('label')->nullable();           // 예: 1번 카운터
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('terminal_id')->nullable()->after('pay_method')->constrained('store_terminals')->nullOnDelete();
            $t->string('card_approval_no', 30)->nullable()->after('terminal_id');   // 단말기 승인번호
            $t->timestamp('card_approved_at')->nullable()->after('card_approval_no');
        });

        // 원본 product_production
        Schema::create('product_productions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_option_id')->nullable()->constrained()->nullOnDelete();
            $t->date('production_date');               // 원본 2026-07-23: 항상 "오늘" (서버에서 고정)
            $t->unsignedInteger('qty');
            $t->string('memo')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('webhook_sent')->default(false);
            $t->timestamps();
            $t->index(['store_id', 'production_date']);
        });

        // 원본 data/webhook_production.log · webhook_order.log (생산·주문 모두)
        Schema::create('integration_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 30);                    // order.completed / production.created|updated|deleted
            $t->unsignedBigInteger('ref_id')->nullable();
            $t->string('url')->nullable();
            $t->boolean('ok');
            $t->string('result');
            $t->json('payload')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('terminal_id');
            $t->dropColumn(['card_approval_no', 'card_approved_at']);
        });
        Schema::dropIfExists('store_terminals');
        Schema::dropIfExists('integration_logs');
        Schema::dropIfExists('product_productions');
        Schema::dropIfExists('vendor_integrations');
        Schema::create('store_webhooks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('url');
            $t->string('secret');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }
};
