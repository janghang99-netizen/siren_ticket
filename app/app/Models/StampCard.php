<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StampCard extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['count' => 0, 'total_earned' => 0];

    protected $casts = ['count' => 'integer', 'total_earned' => 'integer'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(StampProgram::class, 'stamp_program_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
