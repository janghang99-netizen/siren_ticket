<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 판매자 정산 보고서용 PG 결제내역 누적 저장 (원본 다담다 2026-11-20 settle_pg_upload / settle_pg_row).
 *  - 정산 단위인 업체(vendor)별로 쌓는다. 원본 seller_id = 이관 앱 업체.
 *  - 같은 줄을 다시 불러와도 중복 저장되지 않게 (vendor_id, ukey) 고유.
 *    ukey = 페이레터 거래번호(tid)|상태|금액   (거래번호가 없으면 승인번호|승인일|상태|금액)
 *  - 주문·결제·정산 테이블은 건드리지 않는 보고서 전용 테이블.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 불러오기 1회 기록 (원본 settle_pg_upload)
        Schema::create('settle_pg_loads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $t->string('label', 200)->default('');          // 예: 페이레터 불러오기 2026-11-01 ~ 2026-11-07
            $t->unsignedInteger('rows_total')->default(0);   // 가져온 줄
            $t->unsignedInteger('rows_new')->default(0);     // 그중 새로 저장된 줄
            $t->date('approved_from')->nullable();          // 승인일 범위 (취소 줄은 원 결제일이라 불러온 날보다 이를 수 있음)
            $t->date('approved_to')->nullable();            // 어디까지 불러왔는지 — 원샷 정산 "불러오지 않음" 경고 기준
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        // PG 결제 1줄 (원본 settle_pg_row). 승인 +금액 / 취소 −금액
        Schema::create('settle_pg_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('load_id')->nullable()->constrained('settle_pg_loads')->cascadeOnDelete();
            $t->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $t->string('ukey', 190);
            $t->string('tid', 80)->default('');             // 페이레터 거래번호
            $t->string('approval_no', 60)->default('');     // 승인번호
            $t->date('approved_on');                         // 승인일 (취소 줄도 원 결제일)
            $t->date('settled_on')->nullable();              // PG 입금일 — 조회 API 에는 없음
            $t->string('kind', 10);                          // online / terminal
            $t->string('pg_code', 40)->default('');         // 페이레터 pgcode (결제수단)
            $t->string('order_no', 80)->default('');        // 온라인 결제의 주문번호
            $t->string('status', 10);                        // 승인 / 취소
            $t->integer('amount');
            $t->timestamps();
            $t->unique(['vendor_id', 'ukey']);
            $t->index(['vendor_id', 'approved_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settle_pg_rows');
        Schema::dropIfExists('settle_pg_loads');
    }
};
