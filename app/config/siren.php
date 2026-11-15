<?php

use App\Services\Payments\MockCardGateway;
use App\Services\Payments\PosGateway;

return [
    // 결제수단 => 환불 구현. 여기 없는 결제수단은 주문 생성 시 거부된다.
    'gateways' => [
        'card' => MockCardGateway::class,
        'pos_cash' => PosGateway::class,
        'pos_transfer' => PosGateway::class,
        'pos_card' => PosGateway::class,
    ],

    // POS 전용 결제수단 (고객 온라인 주문에서는 사용 불가)
    'pos_methods' => ['pos_cash', 'pos_transfer', 'pos_card'],

    // 미결제 주문 만료(분) — 재고·캐파 점유 해제. 인터미션 135건 캐파가 미결제로 잠기는 걸 막는다.
    'pending_ttl_minutes' => (int) env('SIREN_PENDING_TTL', 10),

    // 매장 보드: 수령완료 주문을 몇 분간 더 보여줄지 (원본 5분)
    'board_picked_up_minutes' => 5,

    'webhook_timeout' => 5,
];
