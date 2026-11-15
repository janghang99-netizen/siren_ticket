<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 입점업체 — 한 업체가 여러 매장을 운영한다
        Schema::create('vendors', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('business_no', 20)->nullable();      // 사업자등록번호
            $t->string('representative')->nullable();        // 대표자
            $t->string('contact_phone', 20)->nullable();
            $t->string('contact_email')->nullable();
            $t->string('bank_name', 30)->nullable();
            $t->string('bank_account', 40)->nullable();
            $t->string('account_holder', 50)->nullable();
            $t->decimal('commission_rate', 5, 2)->default(8.50); // 매장 신규 생성 시 기본 수수료
            $t->enum('status', ['active', 'suspended'])->default('active');
            $t->timestamps();
        });

        Schema::table('stores', function (Blueprint $t) {
            $t->foreignId('vendor_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        // 로그인 계정: 슈퍼어드민(플랫폼 회사) / 입점업체
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('store_id');
        });
        Schema::table('users', function (Blueprint $t) {
            $t->enum('role', ['super_admin', 'vendor'])->default('vendor')->after('id');
            $t->foreignId('vendor_id')->nullable()->after('role')->constrained()->nullOnDelete();
        });

        // 정산서 — 업체 × 기간. 기간 안에 수령완료된 주문을 묶는다.
        Schema::create('settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_id')->constrained();
            $t->date('period_start');
            $t->date('period_end');
            $t->unsignedInteger('order_count');
            $t->unsignedBigInteger('gross_amount');           // 매출
            $t->unsignedBigInteger('commission_amount');      // 수수료
            $t->unsignedBigInteger('payout_amount');          // 지급액 = 매출 - 수수료
            $t->unsignedInteger('refund_count')->default(0);  // 같은 기간 환불 건수 (참고용, 환불분은 매출에 이미 없음)
            $t->unsignedBigInteger('refund_amount')->default(0);
            $t->enum('status', ['pending', 'paid'])->default('pending');
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->index(['vendor_id', 'period_start']);
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('settlement_id')->nullable()->after('ticket_id')->constrained()->nullOnDelete();
            $t->timestamp('settled_at')->nullable()->after('refunded_at');
            $t->string('cancel_reason', 50)->nullable()->after('settled_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('settlement_id');
            $t->dropColumn(['settled_at', 'cancel_reason']);
        });
        Schema::dropIfExists('settlements');
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('vendor_id');
            $t->dropColumn('role');
            $t->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('stores', function (Blueprint $t) {
            $t->dropConstrainedForeignId('vendor_id');
        });
        Schema::dropIfExists('vendors');
    }
};
