<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberSocialAccount extends Model
{
    protected $fillable = ['member_id', 'provider', 'provider_user_id', 'email'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
