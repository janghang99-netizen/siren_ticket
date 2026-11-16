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
        // 마일리지 (원본 HAPPY_CONFIG point_use_cut · CONF percent_point 대응)
        'mileage_min_use' => '1000',              // 최소 사용액
        'mileage_use_max_rate' => '100',          // 결제액 중 마일리지로 낼 수 있는 최대 %
        'mileage_product_max_rate' => '10',       // 업체가 상품에 거는 적립금 상한 (판매가의 %)
        'ticket_mileage_rate' => '1',             // 공연 관람 적립 (티켓 결제액의 %, 운영사 부담)
        'ticketing_enabled' => '0',               // 공연 티켓 판매 기능 (끄면 예매·내 티켓·공연장 좌석표 메뉴가 숨는다 — 다담다 이관 기본값: 끔)
    ];

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
