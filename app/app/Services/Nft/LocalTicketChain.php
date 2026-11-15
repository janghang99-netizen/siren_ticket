<?php

namespace App\Services\Nft;

/** 체인에 올리지 않음 — DB 에만 발행 기록을 남긴다 (체인 미정·중국 거주 회원). 트랜잭션 해시는 자리표시 값. */
class LocalTicketChain implements TicketChain
{
    public function network(): string
    {
        return 'local';
    }

    public function contract(): string
    {
        return (string) config('siren.nft.local_contract');
    }

    public function createWallet(int $customerId): array
    {
        return ['address' => '0x'.substr(hash_hmac('sha256', 'wallet|'.$customerId, (string) config('app.key')), 0, 40), 'secret' => null];
    }

    public function mint(string $tokenId, string $to, string $metadataUri, string $metadataHash): string
    {
        return $this->fake("mint|{$tokenId}|{$to}|{$metadataHash}");
    }

    public function burn(string $tokenId): string
    {
        return $this->fake("burn|{$tokenId}");
    }

    public function markUsed(string $tokenId): string
    {
        return $this->fake("used|{$tokenId}");
    }

    private function fake(string $s): string
    {
        return '0x'.hash('sha256', $s.'|'.microtime(true));
    }
}
