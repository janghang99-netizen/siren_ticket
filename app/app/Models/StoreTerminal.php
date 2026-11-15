<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 매장 현장 카드 단말기 (밴사 · TID) — 현장판매 카드결제에 단말기·승인번호를 남겨 단말기 매출과 대사한다 */
class StoreTerminal extends Model
{
    /** 자주 쓰는 밴사 (직접 입력도 가능) */
    public const PROVIDERS = ['KSNET', 'NICE', 'KICC', 'KIS', 'KCP', 'SMARTRO', 'JTNET', 'KOVAN'];

    protected $fillable = ['store_id', 'provider', 'tid', 'label', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** 이 단말기로 받은 결제가 있는지 (있으면 지우지 않고 사용 중지만) */
    public function hasOrders(): bool
    {
        return Order::where('terminal_id', $this->id)->exists();
    }
}
