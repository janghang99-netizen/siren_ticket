<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** 비회원 팬 — 휴대폰 번호 하나가 한 사람. 마일리지·쿠폰·스탬프가 여기에 묶인다. */
class Customer extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['mileage_balance' => 0];

    protected $casts = ['mileage_balance' => 'integer', 'phone_verified_at' => 'datetime'];

    public static function normalize(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }

    /** 번호로 찾고, 없으면 만든다. PG에는 번호 대신 customer_key 를 user_id 로 보낸다. */
    public static function forPhone(string $phone): self
    {
        $phone = self::normalize($phone);

        return static::firstOrCreate(['phone' => $phone], ['customer_key' => 'C'.strtoupper(Str::random(15))]);
    }

    /**
     * 번호를 인증하지 않은 회원(이메일 인증 가입)의 지갑. 휴대폰 번호 대신 회원 고유 키(M + 회원번호)로 만들어
     * 그 번호로 쌓인 다른 사람의 혜택과 섞이지 않는다.
     */
    public static function forMember(Member $member): self
    {
        return static::firstOrCreate(['phone' => 'M'.$member->id], ['customer_key' => 'C'.strtoupper(Str::random(15))]);
    }

    /** 문자를 보낼 수 있는 번호 (회원 고유 키로 만든 지갑은 번호가 없다) */
    public function smsPhone(): ?string
    {
        return ctype_digit((string) $this->phone) ? $this->phone : null;
    }

    /** 010-****-5678 */
    public function maskedPhone(): string
    {
        $p = $this->phone;

        return strlen($p) >= 10 ? substr($p, 0, 3).'-****-'.substr($p, -4) : $p;
    }

    public function couponIssues(): HasMany
    {
        return $this->hasMany(CouponIssue::class);
    }

    public function stampCards(): HasMany
    {
        return $this->hasMany(StampCard::class);
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(BenefitLedger::class);
    }
}
