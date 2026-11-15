<?php

namespace App\Services\Nft\Evm;

use kornrunner\Keccak;

/** 필요한 만큼만 구현한 Solidity ABI 인코더 (address, uint256, bytes32, string) */
final class Abi
{
    public static function selector(string $signature): string
    {
        return substr(Keccak::hash($signature, 256), 0, 8);
    }

    /**
     * @param  array<int, array{0:string, 1:mixed}>  $args  [[타입, 값], ...]
     * @return string 0x 없는 hex
     */
    public static function encode(array $args): string
    {
        $heads = [];
        $tails = '';
        $headSize = 32 * count($args);
        foreach ($args as [$type, $value]) {
            if ($type === 'string') {
                $heads[] = self::word(dechex($headSize + intdiv(strlen($tails), 2)));
                $bin = (string) $value;
                $tails .= self::word(dechex(strlen($bin))).($bin === '' ? '' : str_pad(bin2hex($bin), (int) ceil(strlen($bin) / 32) * 64, '0'));
            } else {
                $heads[] = match ($type) {
                    'address' => self::word(strtolower(Hex::strip($value))),
                    'uint256' => self::word(is_int($value) ? dechex($value) : Hex::strip((string) $value)),
                    'bytes32' => str_pad(Hex::strip($value), 64, '0'),
                };
            }
        }

        return implode('', $heads).$tails;
    }

    public static function call(string $signature, array $args): string
    {
        return '0x'.self::selector($signature).self::encode($args);
    }

    private static function word(string $hex): string
    {
        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }
}
