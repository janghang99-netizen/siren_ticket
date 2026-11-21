<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'status' => OrderStatus::class,
        'total_amount' => 'integer',
        'coupon_discount' => 'integer',
        'mileage_used' => 'integer',
        'paid_amount' => 'integer',
        'mileage_earn' => 'integer',
        'commission_rate' => 'decimal:2',
        'paid_at' => 'datetime',
        'preparing_at' => 'datetime',
        'ready_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'refunded_at' => 'datetime',
        'webhook_sent_at' => 'datetime',
        'settled_at' => 'datetime',
        'settle_due_on' => 'date',   // 정산예정일 (원본에서 옮겨 온 주문만 저장값, 비면 주문일 + 설정 일수)
        'terms_agreed_at' => 'datetime',
        'adult_confirmed_at' => 'datetime',
        'requires_id_check' => 'boolean',
        'cancel_requested_at' => 'datetime',
        'cancel_responded_at' => 'datetime',
    ];

    /** 업체 매출 기준액 = 판매액 − 업체 부담 쿠폰 (운영사 쿠폰·마일리지 사용분은 운영사가 업체에 채워 준다) */
    public function vendorBase(): int
    {
        return $this->total_amount - ($this->coupon_funded_by === 'vendor' ? $this->coupon_discount : 0);
    }

    public function commission(): int
    {
        return (int) round($this->vendorBase() * (float) $this->commission_rate / 100);
    }

    /** 이 주문으로 업체가 받는 정산금 = 기준액 − 수수료 − 수수료 부가세 − 업체 부담 적립금 */
    public function vendorNet(): int
    {
        $commission = $this->commission();
        $vat = (int) round($commission * (float) Setting::get('commission_vat_rate') / 100);

        return $this->vendorBase() - $commission - $vat - (int) $this->mileage_earn;
    }

    /** 운영사가 대신 낸 금액 (운영사 쿠폰 + 마일리지 사용) */
    public function platformBenefit(): int
    {
        return ($this->coupon_funded_by === 'platform' ? $this->coupon_discount : 0) + (int) $this->mileage_used;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function couponIssue(): BelongsTo
    {
        return $this->belongsTo(CouponIssue::class);
    }

    /** 매장 답변을 기다리는 구매자 취소 요청 */
    public function hasPendingCancelRequest(): bool
    {
        return $this->cancel_requested_at !== null && $this->cancel_responded_at === null;
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function getRouteKeyName(): string
    {
        return 'order_no';
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function capacitySlot(): BelongsTo
    {
        return $this->belongsTo(CapacitySlot::class);
    }

    public function toBoardArray(): array
    {
        return [
            'order_no' => $this->order_no,
            'pickup_code' => $this->pickup_code,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'channel' => $this->channel,
            'pay_method' => $this->pay_method,
            'total_amount' => $this->total_amount,
            'ordered_at' => $this->created_at?->format('H:i'),
            'ordered_ts' => $this->created_at?->getTimestamp(),
            // 되돌리기 가능 여부 (건네준 주문은 정산 전 + 보드에 남아 있는 동안만)
            'can_revert' => $this->status->previous() !== null && ($this->status !== OrderStatus::PickedUp
                || (! $this->settlement_id && $this->picked_up_at?->gte(now()->subMinutes((int) config('siren.board_picked_up_minutes'))))),
            'updated_at' => $this->updated_at?->format('H:i'),
            'cancel_disabled' => $this->status !== OrderStatus::Paid,
            'id_check' => $this->requires_id_check,
            'cancel_request' => $this->hasPendingCancelRequest() ? $this->cancel_request_reason : null,
            'items' => $this->items->map(fn (OrderItem $i) => [
                'name' => $i->name,
                'option' => $i->option_name,
                'qty' => $i->qty,
                'dine_option' => $i->dine_option,
            ])->all(),
        ];
    }
}
