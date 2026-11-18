<?php

namespace App\Services\Nft;

/**
 * 티켓 NFT를 체인에 기록하는 자리. 구현: LocalTicketChain(기록만), KaiaTicketChain(카이아).
 * config/siren.php 의 nft.chain 으로 고른다. nft.offchain_residence 에 넣은 거주 국가만 로컬(기본은 비어 있음).
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

    /** 이 번호의 토큰을 지금 가진 지갑 주소. 그런 토큰이 체인에 없으면 null */
    public function ownerOf(string $tokenId): ?string;
}
