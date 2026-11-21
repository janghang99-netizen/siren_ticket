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

    /** 주류판매 자격 상태 (원본 alcohol_seller_status.status) — null 은 '신청 없음' */
    public const LIQUOR_STATES = ['pending' => '확인 대기', 'approved' => '승인', 'rejected' => '반려'];

    /**
     * 영업형태별 주류 판매 가능 여부 (원본 alcohol_seller_manage.php 안내 문구)
     * allowed=false 인 형태(카페·휴게음식점)는 승인할 수 없다.
     */
    public const LIQUOR_BIZ_TYPES = [
        '제과점' => ['label' => '제과점(베이커리)', 'allowed' => true,
            'guide' => '2009년 식품위생법 시행규칙 개정 이후 의제주류판매업면허를 받을 수 있어요. 면허(신고)증을 확인해 주세요.'],
        '일반음식점' => ['label' => '일반음식점', 'allowed' => true,
            'guide' => '의제주류판매업면허로 주류를 팔 수 있어요. 영업신고증의 업종이 일반음식점인지 확인해 주세요.'],
        '카페' => ['label' => '카페(휴게음식점)', 'allowed' => false,
            'guide' => '휴게음식점은 원칙적으로 주류를 팔 수 없어요. 일반음식점으로 다시 영업신고한 뒤 신청해야 해요.'],
        '기타' => ['label' => '기타', 'allowed' => true,
            'guide' => '위에 없는 형태는 서류로 주류 판매 근거(면허·신고)를 확인하고, 메모에 확인 내용을 꼭 남겨 주세요.'],
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'commission_rate' => 'decimal:2',
        'documents' => 'array',
        'approved_at' => 'datetime',
        'liquor_requested_at' => 'datetime',
        'liquor_checked_at' => 'datetime',
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

    /** 운영사가 서류를 확인해 주류 판매를 승인했나 (원본 alcohol_seller_status.status='y') */
    public function canSellLiquor(): bool
    {
        return $this->liquor_status === 'approved';
    }

    public function liquorStatusLabel(): string
    {
        return self::LIQUOR_STATES[$this->liquor_status] ?? '신청 없음';
    }

    public function liquorBizLabel(): ?string
    {
        return self::LIQUOR_BIZ_TYPES[$this->liquor_biz_type]['label'] ?? $this->liquor_biz_type;
    }

    public function liquorChecker(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'liquor_checked_by');
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
