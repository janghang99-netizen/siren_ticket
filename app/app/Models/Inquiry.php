<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 1:1 문의 */
class Inquiry extends Model
{
    public const CATEGORIES = ['order' => '주문·결제', 'store' => '매장·상품', 'account' => '회원·계정', 'etc' => '기타'];

    protected $fillable = ['customer_id', 'member_id', 'category', 'store_id', 'order_no', 'title', 'body'];

    protected $casts = ['answered_at' => 'datetime'];

    protected $attributes = ['status' => 'open'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? '기타';
    }

    public function isAnswered(): bool
    {
        return $this->status === 'answered';
    }
}
