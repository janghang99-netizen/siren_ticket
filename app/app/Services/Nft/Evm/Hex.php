<?php

namespace App\Services\Nft\Evm;

final class Hex
{
    public static function strip(string $hex): string
    {
        return str_starts_with($hex, '0x') || str_starts_with($hex, '0X') ? substr($hex, 2) : $hex;
    }

    public static function toBin(string $hex): string
    {
        $h = self::strip($hex);

        return hex2bin(strlen($h) % 2 ? '0'.$h : $h);
    }

    /** "0x1a" → 26 (RPC 응답용, PHP 정수 범위) */
    public static function toInt(string $hex): int
    {
        return (int) hexdec(self::strip($hex));
    }
}
