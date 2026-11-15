<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 혜택 — 마일리지 · 쿠폰 · 스탬프 (원본 다담다 auction_point_jangboo / stamp_* 대응, 휴대폰 번호 기준)
 *  - customers: 비회원 팬을 휴대폰 번호로 식별. PG user_id 에는 번호 대신 customer_key 를 보낸다.
 *  - benefit_ledger: 모든 적립·사용·회수 기록. 행마다 직전 행 해시를 이어 붙여(해시 체인) 위변조를 드러내고,
 *    ledger_anchors 로 구간 머클 루트를 남겨 나중에 블록체인에 올릴 수 있게 한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('phone', 20)->unique();             // 숫자만
            $t->string('customer_key', 24)->unique();      // PG user_id · 외부 식별자 (번호 노출 방지)
            $t->integer('mileage_balance')->default(0);
            $t->timestamp('phone_verified_at')->nullable();
            $t->timestamps();
        });

        Schema::create('phone_verifications', function (Blueprint $t) {
            $t->id();
            $t->string('phone', 20)->index();
            $t->string('code_hash', 64);
            $t->timestamp('expires_at');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
        });

        Schema::create('coupons', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('issuer', 10);                       // platform(운영사 부담) / vendor(업체 부담)
            $t->foreignId('vendor_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('scope', 10)->default('order');      // order(매장 주문) / ticket(공연 티켓)
            $t->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $t->string('kind', 10);                         // fixed / percent / free_item
            $t->unsignedInteger('value')->default(0);       // 원 또는 %
            $t->unsignedInteger('max_discount')->nullable();
            $t->unsignedInteger('min_amount')->default(0);
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();   // free_item
            $t->string('code', 30)->nullable()->unique();   // 코드 입력형
            $t->boolean('is_public')->default(false);       // 매장·공연 화면에서 받기
            $t->string('source', 10)->default('manual');    // manual / stamp
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->unsignedSmallInteger('valid_days')->nullable();   // 받은 날부터 사용 기한
            $t->unsignedInteger('total_limit')->nullable();
            $t->unsignedInteger('issued_count')->default(0);
            $t->unsignedInteger('used_count')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('coupon_issues', function (Blueprint $t) {
            $t->id();
            $t->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('seq')->default(1);         // 스탬프 보상처럼 같은 쿠폰을 여러 장 받을 때 순번
            $t->string('status', 10)->default('issued');    // issued / used
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('used_at')->nullable();
            $t->string('used_for', 10)->nullable();         // order / ticket
            $t->string('used_ref', 40)->nullable();         // 주문번호 / 예매번호
            $t->timestamps();
            $t->unique(['coupon_id', 'customer_id', 'seq']);   // 1인 1매 쿠폰의 중복 발급을 DB가 막는다
        });

        Schema::create('stamp_programs', function (Blueprint $t) {
            $t->id();
            $t->string('owner', 10);                        // store(매장 주문) / platform(공연 관람)
            $t->foreignId('store_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('need_count')->default(10);
            $t->unsignedInteger('min_amount')->default(0);
            $t->foreignId('reward_coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('stamp_cards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stamp_program_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->integer('count')->default(0);
            $t->unsignedInteger('total_earned')->default(0);
            $t->timestamps();
            $t->unique(['stamp_program_id', 'customer_id']);
        });

        Schema::create('ledger_anchors', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('from_id');
            $t->unsignedBigInteger('to_id');
            $t->unsignedInteger('row_count');
            $t->string('merkle_root', 64);
            $t->string('head_hash', 64);
            $t->string('network', 30)->default('local');    // local / 체인 이름
            $t->string('tx_hash', 100)->nullable();
            $t->timestamp('anchored_at');
            $t->timestamps();
        });

        Schema::create('benefit_ledger', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained();
            $t->string('kind', 10);                         // mileage / stamp / coupon
            $t->string('type', 10);                         // earn / use / restore / revoke / adjust / issue / redeem
            $t->integer('amount');                          // 마일리지 원, 스탬프 개수, 쿠폰 1/-1 (부호 포함)
            $t->integer('balance_after')->nullable();
            $t->unsignedBigInteger('ref_id')->nullable();   // 스탬프 프로그램 / 쿠폰 발급 id
            $t->string('subject', 40)->nullable();          // order:주문번호 / ticket:예매번호
            $t->string('memo')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // 관리자 수동 조정
            $t->string('idem_key', 100)->nullable()->unique();   // 같은 적립·차감이 두 번 들어가지 않게
            $t->string('prev_hash', 64)->nullable();
            $t->string('hash', 64);
            $t->foreignId('anchor_id')->nullable()->constrained('ledger_anchors')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['customer_id', 'kind']);
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('customer_id')->nullable()->after('ticket_id')->constrained()->nullOnDelete();
            $t->foreignId('coupon_issue_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $t->unsignedInteger('coupon_discount')->default(0)->after('total_amount');
            $t->string('coupon_funded_by', 10)->nullable()->after('coupon_discount');
            $t->unsignedInteger('mileage_used')->default(0)->after('coupon_funded_by');
            $t->unsignedInteger('paid_amount')->default(0)->after('mileage_used');
            $t->unsignedInteger('mileage_earn')->default(0)->after('paid_amount');   // 수령 시 줄 적립액 (업체 부담)
        });

        Schema::table('tickets', function (Blueprint $t) {
            $t->string('booking_no', 32)->nullable()->index()->after('ticket_no');
            $t->foreignId('customer_id')->nullable()->after('seat_section_id')->constrained()->nullOnDelete();
            $t->foreignId('coupon_issue_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $t->unsignedInteger('coupon_discount')->default(0)->after('price');   // 예매 할인액을 티켓별로 나눈 몫
            $t->unsignedInteger('mileage_used')->default(0)->after('coupon_discount');
            $t->unsignedInteger('paid_amount')->default(0)->after('mileage_used');
            $t->timestamp('benefit_granted_at')->nullable();   // 관람 후 마일리지·스탬프 지급
        });

        Schema::table('products', function (Blueprint $t) {
            $t->unsignedInteger('mileage_points')->default(0)->after('price');   // 1개당 적립 (업체 부담, 원본 auction_product.point)
        });

        Schema::table('settlements', function (Blueprint $t) {
            $t->unsignedBigInteger('coupon_vendor_amount')->default(0)->after('gross_amount');
            $t->unsignedBigInteger('mileage_earn_amount')->default(0)->after('coupon_vendor_amount');
            $t->unsignedBigInteger('platform_benefit_amount')->default(0)->after('mileage_earn_amount');   // 운영사가 대신 낸 쿠폰·마일리지 (지급액에 포함)
        });

        // 기존 주문·티켓: 할인이 없었으므로 결제액 = 판매액
        DB::table('orders')->update(['paid_amount' => DB::raw('total_amount')]);
        DB::table('tickets')->update(['paid_amount' => DB::raw('price')]);
    }

    public function down(): void
    {
        Schema::table('settlements', fn (Blueprint $t) => $t->dropColumn(['coupon_vendor_amount', 'mileage_earn_amount', 'platform_benefit_amount']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('mileage_points'));
        Schema::table('tickets', function (Blueprint $t) {
            $t->dropConstrainedForeignId('customer_id');
            $t->dropConstrainedForeignId('coupon_issue_id');
            $t->dropColumn(['booking_no', 'coupon_discount', 'mileage_used', 'paid_amount', 'benefit_granted_at']);
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('customer_id');
            $t->dropConstrainedForeignId('coupon_issue_id');
            $t->dropColumn(['coupon_discount', 'coupon_funded_by', 'mileage_used', 'paid_amount', 'mileage_earn']);
        });
        foreach (['benefit_ledger', 'ledger_anchors', 'stamp_cards', 'stamp_programs', 'coupon_issues', 'coupons', 'phone_verifications', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
