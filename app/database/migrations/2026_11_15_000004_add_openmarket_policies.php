<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 오픈마켓 정책 (docs/OPENMARKET_POLICY.md) + 생산·재고 원장 */
return new class extends Migration
{
    public function up(): void
    {
        // A. 입점 심사 · 판매자 고지 · C3 정산주기
        Schema::table('vendors', function (Blueprint $t) {
            $t->string('state', 20)->default('pending')->after('name');    // pending 심사중 / approved 승인 / rejected 반려 / suspended 정지
            $t->string('state_reason')->nullable()->after('state');        // 반려·정지 사유
            $t->timestamp('approved_at')->nullable()->after('state_reason');
            $t->string('address')->nullable()->after('representative');
            $t->string('mail_order_no', 40)->nullable()->after('business_no');     // 통신판매업 신고번호
            $t->string('liquor_license_no', 40)->nullable()->after('mail_order_no'); // 주류판매 신고번호
            $t->json('documents')->nullable();                                       // 서류 4종 저장 경로
            $t->string('settlement_cycle', 10)->default('biweekly');                 // weekly / biweekly / monthly
            $t->unsignedTinyInteger('settlement_day')->default(1);                   // 주: 요일(1=월) / 월: 날짜
            $t->unsignedTinyInteger('payout_delay_days')->default(3);                // 정산일 + N일 지급
        });
        DB::table('vendors')->where('status', 'active')->update(['state' => 'approved', 'approved_at' => now()]);
        DB::table('vendors')->where('status', 'suspended')->update(['state' => 'suspended']);
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn('status'));

        // B. 상품 정책
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('is_alcohol')->default(false)->after('price');   // 주류: 성인 확인 + 현장 신분증 확인
            $t->text('description')->nullable()->after('name');
            $t->string('origin')->nullable()->after('description');       // 원산지
            $t->string('allergens')->nullable()->after('origin');         // 알레르기 유발 성분
            $t->timestamp('blocked_at')->nullable();                      // 운영사 판매 중지(제재)
            $t->string('block_reason')->nullable();
        });

        // D·E. 주문
        Schema::table('orders', function (Blueprint $t) {
            $t->boolean('requires_id_check')->default(false)->after('channel');
            $t->timestamp('terms_agreed_at')->nullable()->after('requires_id_check');
            $t->timestamp('adult_confirmed_at')->nullable()->after('terms_agreed_at');
        });

        // F. 티켓 취소 수수료
        Schema::table('tickets', function (Blueprint $t) {
            $t->timestamp('terms_agreed_at')->nullable()->after('paid_at');
            $t->timestamp('cancelled_at')->nullable()->after('checked_in_at');
            $t->unsignedInteger('cancel_fee')->default(0)->after('cancelled_at');
            $t->unsignedInteger('refund_amount')->default(0)->after('cancel_fee');
            $t->string('cancelled_by', 20)->nullable()->after('refund_amount');   // buyer / admin
        });

        // C. 정산 — 부가세, 공제, 지급예정일, 세금계산서, 이의제기
        Schema::table('settlements', function (Blueprint $t) {
            $t->unsignedBigInteger('commission_vat')->default(0)->after('commission_amount');
            $t->bigInteger('deduction_amount')->default(0)->after('commission_vat');
            $t->date('payout_due_on')->nullable()->after('status');
            $t->timestamp('tax_invoice_issued_at')->nullable();
            $t->text('dispute_message')->nullable();
            $t->timestamp('disputed_at')->nullable();
            $t->text('dispute_reply')->nullable();
            $t->timestamp('dispute_replied_at')->nullable();
        });

        // C6·C7 차기 정산 공제 항목
        Schema::create('settlement_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_id')->constrained();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('settlement_id')->nullable()->constrained()->nullOnDelete();   // 반영된 정산서
            $t->bigInteger('amount');                  // 양수 = 지급액에서 차감
            $t->string('reason');
            $t->timestamps();
        });

        // C2·C3 플랫폼 기본값
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key', 60)->primary();
            $t->text('value')->nullable();
            $t->timestamps();
        });

        // H. 생산·재고 원장 — 재고가 바뀌는 모든 일을 한 장부에 (생산·폐기·재고맞춤·판매·취소복구)
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_option_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 20);                     // produce 생산 / discard 폐기 / adjust 재고맞춤 / sale 판매 / restore 취소복구
            $t->integer('qty');                         // 부호 포함 (+입고 / −출고)
            $t->integer('stock_after')->nullable();
            $t->string('memo')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('settlement_adjustments');
        Schema::table('settlements', fn (Blueprint $t) => $t->dropColumn([
            'commission_vat', 'deduction_amount', 'payout_due_on', 'tax_invoice_issued_at',
            'dispute_message', 'disputed_at', 'dispute_reply', 'dispute_replied_at',
        ]));
        Schema::table('tickets', fn (Blueprint $t) => $t->dropColumn(['terms_agreed_at', 'cancelled_at', 'cancel_fee', 'refund_amount', 'cancelled_by']));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['requires_id_check', 'terms_agreed_at', 'adult_confirmed_at']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['is_alcohol', 'description', 'origin', 'allergens', 'blocked_at', 'block_reason']));
        Schema::table('vendors', fn (Blueprint $t) => $t->enum('status', ['active', 'suspended'])->default('active'));
        DB::table('vendors')->update(['status' => DB::raw("CASE WHEN state = 'suspended' THEN 'suspended' ELSE 'active' END")]);
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn([
            'state', 'state_reason', 'approved_at', 'address', 'mail_order_no', 'liquor_license_no',
            'documents', 'settlement_cycle', 'settlement_day', 'payout_delay_days',
        ]));
    }
};
