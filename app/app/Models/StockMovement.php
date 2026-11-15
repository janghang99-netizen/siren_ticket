<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 재고 원장 한 줄: 생산·폐기·재고맞춤·판매·취소복구 */
class StockMovement extends Model
{
    public const TYPES = [
        'produce' => '생산',
        'discard' => '폐기',
        'adjust' => '재고 맞춤',
        'sale' => '판매',
        'restore' => '취소 복구',
    ];

    protected $guarded = ['id'];

    protected $casts = ['qty' => 'integer', 'stock_after' => 'integer'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
