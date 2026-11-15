<?php

namespace App\Services\Nft;

/**
 * 티켓 NFT를 체인에 기록하는 자리. 구현: LocalTicketChain(기록만), KaiaTicketChain(카이아).
 * config/siren.php 의 nft.chain 으로 고르고, 중국 거주 회원은 항상 로컬(체인에 올리지 않음).
 */
interface TicketChain
{
    public function network(): string;

    public function contract(): string;

    /**
     * 운영사 보관 지갑 만들기.
     *
     * @return array{address:string, secret:?string} secret 은 개인키(암호화해 저장) — 로컬은 null
     */
    public function createWallet(int $customerId): array;

    /** @return string 트랜잭션 해시 */
    public function mint(string $tokenId, string $to, string $metadataUri, string $metadataHash): string;

    /** @return string 트랜잭션 해시 */
    public function burn(string $tokenId): string;

    /** 입장 표시 @return string 트랜잭션 해시 */
    public function markUsed(string $tokenId): string;
}
