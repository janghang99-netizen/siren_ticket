<?php

namespace App\Services\Nft;

/** 체인 미정 — DB 에만 발행 기록을 남긴다. 트랜잭션 해시는 입력값의 해시로 만든 자리표시 값. */
class LocalTicketChain implements TicketChain
{
    public function network(): string
    {
        return 'local';
    }

    public function contract(): string
    {
        return (string) config('siren.nft.contract');
    }

    public function createWallet(int $customerId): string
    {
        return '0x'.substr(hash_hmac('sha256', 'wallet|'.$customerId, (string) config('app.key')), 0, 40);
    }

    public function mint(string $tokenId, string $to, string $metadataUri, string $metadataHash): string
    {
        return '0x'.hash('sha256', "mint|{$tokenId}|{$to}|{$metadataHash}|".microtime(true));
    }

    public function burn(string $tokenId): string
    {
        return '0x'.hash('sha256', "burn|{$tokenId}|".microtime(true));
    }
}
