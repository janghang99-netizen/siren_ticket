<?php

namespace App\Services\Nft;

/**
 * 티켓 NFT를 체인에 기록하는 자리. 체인이 정해지면(예: 이더리움 L2·폴리곤, 카이아, 기업용 허가형 체인)
 * 이 인터페이스로 구현을 만들고 config/siren.php 의 nft.chain 만 바꾼다.
 * 실체인 구현은 가스·지연이 있으므로 큐로 보내고, 결과 트랜잭션 해시를 ticket_tokens 에 채우는 방식을 권한다.
 */
interface TicketChain
{
    public function network(): string;

    public function contract(): string;

    /** 운영사 보관 지갑 주소 만들기 (실체인: KMS 에서 키를 만들고 주소만 돌려준다) */
    public function createWallet(int $customerId): string;

    /** @return string 트랜잭션 해시 */
    public function mint(string $tokenId, string $to, string $metadataUri, string $metadataHash): string;

    /** @return string 트랜잭션 해시 */
    public function burn(string $tokenId): string;
}
