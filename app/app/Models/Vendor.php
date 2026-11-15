<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/** 입점업체 — 여러 매장을 운영한다. 심사(state)를 통과해야 판매할 수 있다. */
class Vendor extends Model
{
    public const STATES = ['pending' => '심사 중', 'approved' => '승인', 'rejected' => '반려', 'suspended' => '이용 정지'];

    public const CYCLES = ['weekly' => '매주', 'biweekly' => '2주마다', 'monthly' => '매월'];

    /** 입점계약 제3조 제출 서류 */
    public const DOCUMENTS = [
        'business_reg' => '사업자등록증',
        'mail_order' => '통신판매업 신고증',
        'id_card' => '대표자 신분증',
        'bank_copy' => '정산 계좌 사본',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'commission_rate' => 'decimal:2',
        'documents' => 'array',
        'approved_at' => 'datetime',
        'settlement_day' => 'integer',
        'payout_delay_days' => 'integer',
    ];

    protected $attributes = [
        'commission_rate' => '8.50', 'state' => 'pending',
        'settlement_cycle' => 'biweekly', 'settlement_day' => 1, 'payout_delay_days' => 3,
    ];

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function orders(): HasManyThrough
    {
        return $this->hasManyThrough(Order::class, Store::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(SettlementAdjustment::class);
    }

    /** 승인된 업체만 판매·콘솔 이용 가능 */
    public function isActive(): bool
    {
        return $this->state === 'approved';
    }

    public function stateLabel(): string
    {
        return self::STATES[$this->state] ?? $this->state;
    }

    public function cycleLabel(): string
    {
        $days = ['', '월', '화', '수', '목', '금', '토', '일'];

        return match ($this->settlement_cycle) {
            'monthly' => "매월 {$this->settlement_day}일",
            'weekly' => '매주 '.$days[$this->settlement_day].'요일',
            default => '2주마다 '.$days[$this->settlement_day].'요일',
        };
    }

    /** 오늘이 자동 정산일인가 (격주는 직전 정산 후 8일 이상 지났을 때만) */
    public function isSettlementDay(Carbon $today, ?Carbon $lastPeriodEnd): bool
    {
        return match ($this->settlement_cycle) {
            'weekly' => $today->dayOfWeekIso === $this->settlement_day,
            'biweekly' => $today->dayOfWeekIso === $this->settlement_day
                && ($lastPeriodEnd === null || $lastPeriodEnd->diffInDays($today) >= 8),
            'monthly' => $today->day === min($this->settlement_day, $today->daysInMonth),
            default => false,
        };
    }

    /** 다음 자동 정산일 (화면 안내용) */
    public function nextSettlementDate(?Carbon $lastPeriodEnd = null): Carbon
    {
        $d = now()->startOfDay()->addDay();
        for ($i = 0; $i < 62; $i++, $d->addDay()) {
            if ($this->isSettlementDay($d, $lastPeriodEnd)) {
                return $d;
            }
        }

        return $d;
    }

    /** 판매자 신원정보 고지 (전자상거래법 §20) */
    public function disclosure(): array
    {
        return array_filter([
            '상호' => $this->name,
            '대표자' => $this->representative,
            '사업자등록번호' => $this->business_no,
            '통신판매업 신고번호' => $this->mail_order_no,
            '주류판매 신고번호' => $this->liquor_license_no,
            '주소' => $this->address,
            '연락처' => $this->contact_phone,
            '이메일' => $this->contact_email,
        ]);
    }
}
