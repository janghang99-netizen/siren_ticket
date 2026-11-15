<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 고객(휴대폰 번호)의 운영사 보관 지갑. NFT 티켓이 여기로 발행되고, 마일리지를 토큰·코인으로 바꾸면 같은 주소로 간다.
 * 개인키는 DB에 두지 않는다 (체인 연동 시 KMS·HSM).
 */
class CustomerWallet extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    protected $casts = ['secret' => 'encrypted'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
