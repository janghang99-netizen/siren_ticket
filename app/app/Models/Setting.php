<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 플랫폼 기본값 (key-value). 새 업체를 만들 때 적용된다. */
class Setting extends Model
{
    public const DEFAULTS = [
        'default_commission_rate' => '8.5',       // 입점계약 제7조: 판매금액의 8.5% (부가세 별도)
        'default_settlement_cycle' => 'biweekly', // 입점계약 제7조: 2주
        'default_settlement_day' => '1',          // 월요일
        'default_payout_delay_days' => '3',
        'commission_vat_rate' => '10',            // 수수료 부가세 %
        'settle_due_days' => '7',                 // 상품판매 정산예정일 = 주문일 + N일 (원본 HAPPY_CONFIG calculate_date, 2026-11-21 5→7일: 페이레터 결제가 최대 5일 늦게 조회됨). 원샷 판매자 정산은 이 날이 지난 주문만
        // 마일리지 (원본 HAPPY_CONFIG point_use_cut · CONF percent_point 대응)
        'mileage_min_use' => '1000',              // 최소 사용액
        'mileage_use_max_rate' => '100',          // 결제액 중 마일리지로 낼 수 있는 최대 %
        'mileage_product_max_rate' => '10',       // 업체가 상품에 거는 적립금 상한 (판매가의 %)
        'ticket_mileage_rate' => '1',             // 공연 관람 적립 (티켓 결제액의 %, 운영사 부담)
        'ticketing_enabled' => '0',               // 공연 티켓 판매 기능 (끄면 예매·내 티켓·공연장 좌석표 메뉴가 숨는다 — 다담다 이관 기본값: 끔)
        // 화면 이름 · 운영사 사업자 정보 (화면 하단 표시 — 전자상거래법 통신판매중개자 고지). 기본값은 원본 다담다 하단 공개 정보.
        'brand_name' => 'SIREN Ticket',
        'company_name' => '주식회사 태라글로벌',
        'company_ceo' => '한명아',
        'company_reg_no' => '231-88-02240',
        'company_mail_order_no' => '2021-서울서초-3972',
        'company_address' => '서울특별시 서초구 효령로51길 18 남승빌딩 2층',
        'company_phone' => '02-310-9840',
        'company_email' => '',
        'company_info_visible' => '1',            // 화면 하단 사업자 정보 보이기 (개발·시연 서버에서는 끈다 — 실서비스는 법적 고지라 켜 둔다)
    ];

    /** 화면 이름·사업자 정보 키 (운영사 설정 '사업자 정보' 카드) */
    public const SITE_KEYS = ['brand_name', 'company_name', 'company_ceo', 'company_reg_no', 'company_mail_order_no', 'company_address', 'company_phone', 'company_email', 'company_info_visible'];

    /** @return array<string, string> 화면 이름·사업자 정보 (요청마다 한 번) */
    public static function site(): array
    {
        return array_intersect_key(static::values(), array_flip(self::SITE_KEYS));
    }

    /** 화면 이름 (문자·메일 머리말 등) */
    public static function brand(): string
    {
        return static::get('brand_name') ?: 'SIREN Ticket';
    }

    /** 공연 티켓 판매 기능을 쓰는지 (슈퍼관리자 설정에서 켜고 끈다) */
    public static function ticketingEnabled(): bool
    {
        return static::get('ticketing_enabled') === '1';
    }

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static function get(string $key): string
    {
        return (string) (static::query()->whereKey($key)->value('value') ?? self::DEFAULTS[$key] ?? '');
    }

    /** @return array<string, string> 저장값이 없으면 기본값 */
    public static function values(): array
    {
        $saved = static::query()->pluck('value', 'key')->all();

        return collect(self::DEFAULTS)->map(fn ($v, $k) => (string) ($saved[$k] ?? $v))->all();
    }

    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            static::updateOrCreate(['key' => $key], ['value' => (string) $value]);
        }
    }
}
