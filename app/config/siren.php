<?php

use App\Services\Payments\MockCardGateway;
use App\Services\Payments\PosGateway;

return [
    // 개발·테스트 서버: 검색 엔진(구글·네이버 등)에 수집·노출되지 않게 (robots.txt 전체 차단 + noindex 헤더·메타)
    'noindex' => (bool) env('SITE_NOINDEX', false),

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

    // 매장 보드: 수령완료 주문을 몇 분간 더 보여줄지 (원본 5분 → 실수 되돌리기 위해 10분)
    'board_picked_up_minutes' => 10,   // 이 시간 안에는 '건네줬어요' 를 되돌릴 수 있다

    'webhook_timeout' => 5,

    // 휴대폰 인증 문자: log(개발 — 화면에 번호 표시) / 발송사 연동 시 그 이름으로 바꾸고 SmsSender 를 바인딩
    'sms_driver' => env('SIREN_SMS_DRIVER', 'log'),
    // 매장이 '다 됐어요' 를 누르면 손님에게 준비 완료 문자 (주문 한 건에 한 번)
    'notify_ready_sms' => (bool) env('SIREN_NOTIFY_READY_SMS', true),

    // 혜택 원장 머클 루트를 올릴 곳. 체인이 정해지면 BlockchainAnchor 구현으로 교체
    'blockchain_anchor' => \App\Services\Benefits\LocalAnchor::class,

    // NFT 티켓: 체인 구현과 컨트랙트 주소. 체인이 정해지면 TicketChain 구현으로 바꾼다
    'nft' => [
        // local(체인에 안 올림) / kaia
        'chain' => env('SIREN_NFT_CHAIN', 'local') === 'kaia' ? \App\Services\Nft\KaiaTicketChain::class : \App\Services\Nft\LocalTicketChain::class,
        'local_contract' => '0x5152454e00000000000000000000000000000001',
        // 거주 국가가 여기 있으면 체인에 올리지 않고 서버에만 기록한다. 지금은 비워 둔다 = 모든 나라(중국 포함) 체인 발행.
        // 특정 나라를 다시 빼려면 국가 코드를 넣는다 (예: ['CN']).
        'offchain_residence' => [],
        'kaia' => [
            'network' => env('SIREN_KAIA_NETWORK', 'kaia-kairos'),
            'rpc' => env('SIREN_KAIA_RPC', 'https://public-en-kairos.node.kaia.io'),
            'chain_id' => (int) env('SIREN_KAIA_CHAIN_ID', 1001),
            'contract' => env('SIREN_KAIA_CONTRACT'),   // 비우면 nft:deploy 가 저장한 주소
            'operator_key_file' => storage_path('app/.kaia_operator_key'),
            'explorer' => env('SIREN_KAIA_EXPLORER', 'https://kairos.kaiascan.io'),
        ],
    ],

    // 인증한 휴대폰 번호를 이 세션에서 몇 분 동안 유지할지
    'phone_session_minutes' => 60,
];
