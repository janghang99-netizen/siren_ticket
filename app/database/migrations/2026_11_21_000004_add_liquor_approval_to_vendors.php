<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 주류판매 자격 승인 (원본 다담다 admin/alcohol_seller_manage.php · alcohol_seller_status 테이블)
 *
 * 원본은 판매자 ID 마다 한 줄(영업형태·승인여부·메모·승인일)이라 업체 컬럼으로 옮긴다.
 *  - liquor_status: null = 신청 없음, pending = 서류 확인 대기, approved = 승인, rejected = 반려
 *  - liquor_biz_type: 제과점 · 일반음식점 · 카페 · 기타
 *  - liquor_checked_at / liquor_checked_by: 운영사가 서류를 확인한 시각·사람
 *
 * 기존 업체 처리 (판매가 갑자기 끊기지 않게):
 *  - 이미 주류 상품이 있는 업체 → '승인'으로 옮기되 메모에 "이관 자동 승인 — 서류 재확인 필요"를 남긴다.
 *    운영사가 목록에서 서류를 확인하고 영업형태를 채운다.
 *  - 주류 상품은 없고 신고번호만 넣어 둔 업체 → '대기' (신청한 것으로 본다)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $t) {
            $t->string('liquor_status', 10)->nullable()->after('liquor_license_no');
            $t->string('liquor_biz_type', 20)->nullable()->after('liquor_status');
            $t->timestamp('liquor_requested_at')->nullable()->after('liquor_biz_type');
            $t->timestamp('liquor_checked_at')->nullable()->after('liquor_requested_at');
            $t->foreignId('liquor_checked_by')->nullable()->after('liquor_checked_at')->constrained('users')->nullOnDelete();
            $t->string('liquor_memo', 255)->nullable()->after('liquor_checked_by');
        });

        $withAlcohol = DB::table('products')->join('stores', 'stores.id', '=', 'products.store_id')
            ->where('products.is_alcohol', true)->distinct()->pluck('stores.vendor_id');

        DB::table('vendors')->whereIn('id', $withAlcohol)->update([
            'liquor_status' => 'approved', 'liquor_checked_at' => now(),
            'liquor_memo' => '이관 자동 승인 — 기존 주류 상품 보유. 사업자등록증·영업신고증 재확인 필요',
        ]);
        DB::table('vendors')->whereNotIn('id', $withAlcohol)
            ->whereNotNull('liquor_license_no')->where('liquor_license_no', '!=', '')
            ->update(['liquor_status' => 'pending', 'liquor_requested_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $t) {
            $t->dropConstrainedForeignId('liquor_checked_by');
            $t->dropColumn(['liquor_status', 'liquor_biz_type', 'liquor_requested_at', 'liquor_checked_at', 'liquor_memo']);
        });
    }
};
