<?php

namespace App\Services\Nft\Evm;

/** RLP 인코딩 (이더리움·카이아 트랜잭션 직렬화). 입력: 바이너리 문자열 또는 배열(리스트). */
final class Rlp
{
    public static function encode(string|array $item): string
    {
        if (is_array($item)) {
            $payload = implode('', array_map(self::encode(...), $item));

            return self::length(strlen($payload), 0xc0).$payload;
        }
        if (strlen($item) === 1 && ord($item) < 0x80) {
            return $item;
        }

        return self::length(strlen($item), 0x80).$item;
    }

    /** 0 이상 정수 → 최소 길이 big-endian 바이트 (0 은 빈 문자열). 문자열은 hex 로 본다. */
    public static function int(int|string $value): string
    {
        $hex = is_int($value) ? dechex($value) : ltrim(Hex::strip($value), '0');
        if ($hex === '' || $hex === '0') {
            return '';
        }

        return hex2bin(strlen($hex) % 2 ? '0'.$hex : $hex);
    }

    private static function length(int $len, int $offset): string
    {
        if ($len < 56) {
            return chr($offset + $len);
        }
        $bin = self::int($len);

        return chr($offset + 55 + strlen($bin)).$bin;
    }
}
