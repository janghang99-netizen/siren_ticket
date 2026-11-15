<?php

namespace App\Services\Payments;

use App\Models\Order;

/** 현장판매(현금/계좌이체/현장카드). PG·마일리지 결제가 없으므로 환불은 즉시 성공 (원본과 동일). */
class PosGateway implements PaymentGateway
{
    public function refund(Order $order): bool
    {
        return true;
    }
}
