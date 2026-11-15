<?php

namespace App\Enums;

/**
 * 주문 상태. 값은 원본 다담다 product_stats 숫자를 그대로 쓴다 (비트EIS·원본 데이터 대조용).
 */
enum OrderStatus: int
{
    case Pending = 1;    // 미결제
    case Paid = 2;       // 결제완료
    case Preparing = 3;  // 준비중
    case Ready = 4;      // 준비완료
    case PickedUp = 5;   // 수령완료 (=정산예정)
    case Settled = 6;    // 정산완료
    case Cancelled = 11; // 판매취소
    case Refunded = 14;  // 환불완료

    public function label(): string
    {
        return match ($this) {
            self::Pending => '미결제',
            self::Paid => '결제완료',
            self::Preparing => '준비중',
            self::Ready => '준비완료',
            self::PickedUp => '수령완료',
            self::Settled => '정산완료',
            self::Cancelled => '판매취소',
            self::Refunded => '환불완료',
        };
    }

    /** 매장이 직접 바꿀 수 있는 전이. 취소는 결제완료에서만 (원본: 준비중 이상이면 취소 불가). */
    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Paid => in_array($to, [self::Preparing, self::Ready, self::PickedUp, self::Cancelled], true),
            self::Preparing => in_array($to, [self::Ready, self::PickedUp], true),
            self::Ready => $to === self::PickedUp,
            default => false,
        };
    }

    /**
     * 매장 보드에서 실수로 누른 단계를 한 칸 되돌릴 때의 이전 상태.
     * 수령완료 → 준비완료는 정산 전 + 잠깐 동안만 (OrderService::revert 에서 확인).
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::Preparing => self::Paid,
            self::Ready => self::Preparing,
            self::PickedUp => self::Ready,
            default => null,
        };
    }

    /** @return list<self> 매장 보드에 띄우는 활성 상태 */
    public static function active(): array
    {
        return [self::Paid, self::Preparing, self::Ready];
    }
}
