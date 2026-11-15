<?php

namespace App\Services\Payments;

use App\Models\Order;

/**
 * 개발용 카드결제. 페이레터 실연동 시 같은 인터페이스로 PayletterGateway 를 만들어
 * config/siren.php 의 'card' 항목만 바꾸면 된다 (원본 payletter_cancel_payment() 대응).
 */
class MockCardGateway implements PaymentGateway
{
    public function refund(Order $order): bool
    {
        return true;
    }
}
