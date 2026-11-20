<?php

namespace App\Services\Auth;

/**
 * OTP 앱 2단계 인증 (RFC 6238 TOTP, 30초, 6자리, SHA1).
 * Google Authenticator·Microsoft Authenticator·중국 앱(腾讯身份验证器 등) 모두 같은 표준이라 해외 회원도 쓸 수 있다.
 */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function newSecret(): string
    {
        $bits = '';
        foreach (str_split(random_bytes(20)) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function uri(string $secret, string $account, string $issuer = 'SIREN'): string
    {
        return 'otpauth://totp/'.rawurlencode("{$issuer}:{$account}").'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&digits=6&period=30';
    }

    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('N*', 0, $step), self::decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * 앞뒤 30초 시계 오차까지 허용. 같은 코드 재사용(리플레이)을 막으려고 맞은 타임스텝을 돌려준다.
     *
     * @return int|null 맞으면 타임스텝, 틀리면 null
     */
    public static function verify(string $secret, string $code, ?int $lastUsedStep = null, ?int $now = null): ?int
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            return null;
        }
        // 비밀키가 비었거나 너무 짧으면 누구나 코드를 계산할 수 있다 → 항상 거절
        if (strlen(self::decode($secret)) < 10) {
            return null;
        }
        $current = intdiv($now ?? time(), 30);
        foreach ([0, -1, 1] as $drift) {
            $step = $current + $drift;
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }
            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    private static function decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($secret, '='))) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos !== false) {
                $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
            }
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
