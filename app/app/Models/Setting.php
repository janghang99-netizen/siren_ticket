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
    ];

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
