<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * G1 관리자 조치 감사 로그 — 분쟁 시 "누가 언제 무엇을 했는지" 근거.
 * 실패해도 본 작업을 막지 않도록 예외를 삼킨다(로그 저장 실패는 앱 로그로만).
 */
class Audit
{
    public const LABELS = [
        'vendor.approved' => '업체 승인', 'vendor.rejected' => '업체 반려', 'vendor.suspended' => '업체 정지',
        'product.blocked' => '상품 판매 중지', 'product.unblocked' => '판매 중지 해제',
        'order.refunded' => '주문 환불(운영사)', 'order.cancel_approved' => '취소 요청 승인', 'order.cancel_rejected' => '취소 요청 거절',
        'ticket.cancelled' => '티켓 취소(운영사)', 'event.cancelled' => '공연 취소',
        'settlement.generated' => '정산서 생성', 'settlement.paid' => '정산 지급', 'settlement.replied' => '이의제기 답변',
        'settlement.tax_invoice' => '세금계산서 표시', 'adjustment.created' => '공제 등록', 'settings.saved' => '수수료·정산 설정',
        'order.reverted' => '주문 단계 되돌림', 'translation.run' => '상품명 일괄 번역', 'translation.fix_saved' => '번역 교정 추가', 'translation.fix_deleted' => '번역 교정 삭제',
    ];

    public static function log(string $action, ?Model $subject, string $summary, array $data = []): void
    {
        try {
            DB::table('audit_logs')->insert([
                'user_id' => auth()->id(),
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'summary' => mb_substr($summary, 0, 250),
                'data' => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
                'ip' => request()?->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
