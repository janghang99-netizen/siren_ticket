<?php

namespace App\Services\Nft\Evm;

use Elliptic\EC;
use kornrunner\Keccak;

/**
 * secp256k1 키·주소·레거시(EIP-155) 트랜잭션 서명. 카이아는 이더리움 레거시 트랜잭션을 그대로 받는다.
 * 서명은 검증된 라이브러리(elliptic-php)로 하고, 직접 구현한 것은 RLP 직렬화뿐이다. (gmp 확장 필요)
 */
final class EvmSigner
{
    private EC $ec;

    public function __construct()
    {
        $this->ec = new EC('secp256k1');
    }

    /** @return array{private:string, address:string} 새 키 (private 는 0x 없는 64자리 hex) */
    public function newKey(): array
    {
        $private = bin2hex(random_bytes(32));

        return ['private' => $private, 'address' => $this->address($private)];
    }

    public function address(string $privateKey): string
    {
        $pub = $this->ec->keyFromPrivate(Hex::strip($privateKey), 'hex')->getPublic(false, 'hex');   // 04 + X + Y

        return '0x'.substr(Keccak::hash(hex2bin(substr($pub, 2)), 256), 24);
    }

    /**
     * @param  array{nonce:int, gasPrice:int, gas:int, to:?string, value:int, data:string}  $tx
     * @return string 0x 로 시작하는 서명된 raw 트랜잭션
     */
    public function signLegacy(array $tx, string $privateKey, int $chainId): string
    {
        $fields = [
            Rlp::int($tx['nonce']), Rlp::int($tx['gasPrice']), Rlp::int($tx['gas']),
            $tx['to'] ? Hex::toBin($tx['to']) : '', Rlp::int($tx['value']), $tx['data'] && $tx['data'] !== '0x' ? Hex::toBin($tx['data']) : '',
        ];
        $hash = Keccak::hash(Rlp::encode([...$fields, Rlp::int($chainId), '', '']), 256);
        $sig = $this->ec->keyFromPrivate(Hex::strip($privateKey), 'hex')->sign($hash, ['canonical' => true]);
        $v = $sig->recoveryParam + $chainId * 2 + 35;

        return '0x'.bin2hex(Rlp::encode([...$fields, Rlp::int($v), Rlp::int($sig->r->toString(16)), Rlp::int($sig->s->toString(16))]));
    }

    public static function txHash(string $raw): string
    {
        return '0x'.Keccak::hash(Hex::toBin($raw), 256);
    }
}
