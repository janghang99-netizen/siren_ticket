<?php

namespace App\Services\Payments;

use App\Models\Order;

/**
 * 결제수단별 환불 구현.
 *
 * 원본(ajax_seller_order_update.php)은 in_type 문자열로 if/elseif 분기했고, 새 결제수단을 추가할 때
 * 분기를 빠뜨리면 주문이 stats=11(판매취소)에 갇혀 어느 목록에도 안 떴다(현장판매 7건 실종 사고).
 * 여기서는 config/siren.php 의 gateways 맵에 등록되지 않은 결제수단은 주문 생성 단계에서 거부된다.
 */
interface PaymentGateway
{
    /** 환불 성공 시 true. false 면 주문 상태는 바뀌지 않는다(원본: PG 환불 실패 시 DB 미변경). */
    public function refund(Order $order): bool;
}
