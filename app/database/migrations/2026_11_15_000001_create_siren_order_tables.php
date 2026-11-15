<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 매장 (원본: 판매자 회원 happy_member)
        Schema::create('stores', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();          // 원본 seller_id
            $t->string('name');
            $t->decimal('commission_rate', 5, 2)->default(8.50); // %
            $t->boolean('is_open')->default(true);
            $t->timestamps();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('store_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        // 상품 (원본: auction_product)
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->unsignedInteger('price');               // 원 단위 정수 (원본 DECIMAL 100배 버그 방지)
            $t->integer('stock')->nullable();           // null = 재고관리 안 함 (옵션 없이 파는 상품용)
            $t->enum('stock_deduct_at', ['order', 'payment'])->default('order'); // 원본 jaego_change 0/1
            $t->boolean('is_visible')->default(true);
            $t->unsignedInteger('sort')->default(0);
            $t->timestamps();
        });

        // 옵션 (원본: auction_product_cate2)
        Schema::create('product_options', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->integer('extra_price')->default(0);
            $t->integer('stock')->nullable();           // null = 무제한
            $t->timestamps();
        });

        // 공연
        Schema::create('events', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->dateTime('starts_at');
            $t->timestamps();
        });

        // 캐파 게이트 (원본 설계문서 arena_intermission_capacity_design.md §3 제안 B)
        Schema::create('capacity_slots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->cascadeOnDelete();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->enum('slot', ['pre', 'intermission', 'post']);
            $t->unsignedInteger('capacity');
            $t->unsignedInteger('used')->default(0);
            $t->dateTime('closes_at')->nullable();      // 시간 마감 (카운트 소진과 먼저 오는 쪽)
            $t->boolean('is_closed')->default(false);   // 매장 수동 마감
            $t->timestamps();
            $t->unique(['event_id', 'store_id', 'slot']);
        });

        // 주문 (원본: auction_jangboo + auction_jangproduct 상태)
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('order_no', 64)->unique();       // 원본 or_no / gou_number
            $t->char('pickup_code', 4);                 // 전광판 표시용 4자리
            $t->foreignId('store_id')->constrained();
            $t->foreignId('capacity_slot_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedTinyInteger('status')->default(1);
            $t->enum('channel', ['online', 'pos'])->default('online');
            $t->string('pay_method', 30);
            $t->string('buyer_phone', 20)->nullable();
            $t->unsignedInteger('total_amount');
            $t->decimal('commission_rate', 5, 2);       // 주문 시점 스냅샷
            $t->string('pg_tid')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('preparing_at')->nullable();
            $t->timestamp('ready_at')->nullable();      // 원본 stats4_date
            $t->timestamp('picked_up_at')->nullable();  // 원본 arrive_date
            $t->timestamp('cancelled_at')->nullable();  // 원본 sell_cancel_date
            $t->timestamp('refunded_at')->nullable();
            $t->timestamp('webhook_sent_at')->nullable();
            $t->timestamps();
            $t->index(['store_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained();
            $t->foreignId('product_option_id')->nullable()->constrained();
            $t->string('name');                         // 스냅샷
            $t->string('option_name')->nullable();
            $t->unsignedInteger('unit_price');
            $t->unsignedInteger('qty');
            $t->unsignedInteger('line_total');
            $t->enum('dine_option', ['매장', '포장'])->default('포장');
            $t->boolean('stock_deducted')->default(false); // 이 플래그 기준으로만 원복 (이중차감/이중원복 방지)
            $t->timestamps();
        });

        // 원본 openmarket_seller_alarm
        Schema::create('store_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('type', 30)->default('new_order');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['store_id', 'read_at']);
        });

        // 원본 seller_webhook
        Schema::create('store_webhooks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('url');
            $t->string('secret');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // 원본 data/webhook_order.log
        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('url')->nullable();
            $t->boolean('ok');
            $t->string('result');                       // OK (HTTP 200) / FAIL (HTTP 404) / FAIL (timeout) / FAIL (no config)
            $t->json('payload')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('store_webhooks');
        Schema::dropIfExists('store_notifications');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('capacity_slots');
        Schema::dropIfExists('events');
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('products');
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('store_id');
        });
        Schema::dropIfExists('stores');
    }
};
