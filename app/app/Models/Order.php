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
        'commission_rate' => 'decimal:2',
        'paid_at' => 'datetime',
        'preparing_at' => 'datetime',
        'ready_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'refunded_at' => 'datetime',
        'webhook_sent_at' => 'datetime',
    ];

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
            'updated_at' => $this->updated_at?->format('H:i'),
            'cancel_disabled' => $this->status !== OrderStatus::Paid,
            'items' => $this->items->map(fn (OrderItem $i) => [
                'name' => $i->name,
                'option' => $i->option_name,
                'qty' => $i->qty,
                'dine_option' => $i->dine_option,
            ])->all(),
        ];
    }
}
