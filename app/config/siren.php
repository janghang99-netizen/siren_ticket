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

    // 휴대폰 인증 문자: log(개발 — 화면에 번호 표시) / 발송사 연동 시 그 이름으로 바꾸고 SmsSender 를 바인딩
    'sms_driver' => env('SIREN_SMS_DRIVER', 'log'),

    // 혜택 원장 머클 루트를 올릴 곳. 체인이 정해지면 BlockchainAnchor 구현으로 교체
    'blockchain_anchor' => \App\Services\Benefits\LocalAnchor::class,

    // NFT 티켓: 체인 구현과 컨트랙트 주소. 체인이 정해지면 TicketChain 구현으로 바꾼다
    'nft' => [
        'chain' => \App\Services\Nft\LocalTicketChain::class,
        'contract' => env('SIREN_NFT_CONTRACT', '0x5152454e00000000000000000000000000000001'),
    ],

    // 인증한 휴대폰 번호를 이 세션에서 몇 분 동안 유지할지
    'phone_session_minutes' => 60,
];
