<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** 팬 회원 (티켓 예매). 로그인 세션은 'member' 가드 — 운영사·업체 계정과 섞이지 않는다. */
class Member extends Authenticatable
{
    /** 가입 가능 국가: 국가코드 => [국가번호, 이름]. 한국 외 번호는 국가번호를 붙여 저장한다. */
    public const COUNTRIES = [
        // 이름은 한국어 — 화면에서 __() 로 각 언어로 바꾼다 (lang/*.json)
        'KR' => ['82', '대한민국'], 'CN' => ['86', '중국'], 'JP' => ['81', '일본'], 'TW' => ['886', '대만'],
        'HK' => ['852', '홍콩'], 'MO' => ['853', '마카오'], 'TH' => ['66', '태국'], 'VN' => ['84', '베트남'],
        'PH' => ['63', '필리핀'], 'ID' => ['62', '인도네시아'], 'SG' => ['65', '싱가포르'],
        'MY' => ['60', '말레이시아'], 'US' => ['1', '미국'],
    ];

    protected $guarded = ['id', 'password', 'two_factor_secret', 'two_factor_recovery_codes', 'failed_logins', 'locked_until', 'session_version'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected $attributes = ['failed_logins' => 0, 'session_version' => 1, 'locale' => 'ko', 'country' => 'KR', 'residence_country' => 'KR'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'locked_until' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * 휴대폰 번호 정규화. 한국은 고객(비회원 주문)과 같은 형식(01012345678), 해외는 국가번호+번호(8613800138000).
     * 잘못된 번호면 null.
     */
    public static function normalizePhone(string $country, string $raw): ?string
    {
        $code = self::COUNTRIES[$country][0] ?? null;
        $digits = preg_replace('/\D/', '', $raw);
        if (! $code || $digits === '') {
            return null;
        }
        if (str_starts_with(trim($raw), '+') || str_starts_with($digits, '00'.$code)) {
            $digits = ltrim($digits, '0');
            if (! str_starts_with($digits, $code)) {
                return null;   // 고른 나라와 번호의 국가번호가 다르다
            }
            $digits = substr($digits, strlen($code));
        }
        $national = ltrim($digits, '0');

        if ($country === 'KR') {
            return preg_match('/^1[0-9]\d{7,8}$/', $national) ? '0'.$national : null;
        }
        if ($country === 'CN' && ! preg_match('/^1[3-9]\d{9}$/', $national)) {
            return null;   // 중국 휴대폰: 1로 시작하는 11자리
        }

        return strlen($national) >= 6 && strlen($national) <= 13 ? $code.$national : null;
    }

    /** 이메일 또는 휴대폰(국가번호 포함 가능)으로 회원 찾기 */
    public static function findByIdentifier(string $identifier): ?self
    {
        $identifier = trim($identifier);
        if (str_contains($identifier, '@')) {
            return static::where('email', mb_strtolower($identifier))->first();
        }
        $digits = preg_replace('/\D/', '', $identifier);
        if ($digits === '') {
            return null;
        }
        $candidates = [$digits];
        if (str_starts_with($digits, '82')) {
            $candidates[] = '0'.substr($digits, 2);   // +82 10-... → 010...
        }
        if (str_starts_with($digits, '00')) {
            $candidates[] = ltrim($digits, '0');
        }

        return static::whereIn('phone', $candidates)->first();
    }

    /** 거주 국가 이름 */
    public function residenceLabel(): string
    {
        return self::COUNTRIES[$this->residence_country][1] ?? $this->residence_country;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /** 추가 인증 코드를 보낼 곳: 인증된 휴대폰 > 인증된 이메일 (중국 회원은 보통 이메일) */
    public function stepUpChannel(): array
    {
        if ($this->phone_verified_at) {
            return ['sms', $this->phone];
        }

        return ['email', (string) $this->email];
    }

    public function maskedPhone(): string
    {
        $p = $this->phone;

        return strlen($p) > 7 ? substr($p, 0, 3).str_repeat('*', strlen($p) - 7).substr($p, -4) : $p;
    }

    /** 홍길동 → 홍*동, 홍길 → 홍*, 남궁민수 → 남**수 */
    public function maskedName(): string
    {
        $n = (string) $this->name;
        $len = mb_strlen($n);
        if ($len <= 1) {
            return $n;
        }
        if ($len === 2) {
            return mb_substr($n, 0, 1).'*';
        }

        return mb_substr($n, 0, 1).str_repeat('*', $len - 2).mb_substr($n, -1);
    }

    public function maskedEmail(): ?string
    {
        if (! $this->email) {
            return null;
        }
        [$local, $domain] = explode('@', $this->email, 2);

        return mb_substr($local, 0, 2).str_repeat('*', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(MemberDevice::class);
    }

    public function loginEvents(): HasMany
    {
        return $this->hasMany(MemberLoginEvent::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function socialAccounts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MemberSocialAccount::class);
    }
}
