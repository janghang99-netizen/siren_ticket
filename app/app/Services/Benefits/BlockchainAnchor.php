<?php

namespace App\Services\Benefits;

/**
 * 원장 머클 루트를 외부 체인에 기록하는 자리.
 * 체인이 정해지면(예: 이더리움 계열 L2, 클레이튼/카이아, 하이퍼레저 등) 이 인터페이스로 구현을 만들고
 * config/siren.php 의 blockchain_anchor 만 바꾼다. 개인정보는 올리지 않는다 — 해시 하나만 올라간다.
 */
interface BlockchainAnchor
{
    /** @return array{0:string, 1:?string} [네트워크 이름, 트랜잭션 해시] */
    public function publish(string $merkleRoot, int $fromId, int $toId): array;
}
