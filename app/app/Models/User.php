<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** 비밀번호 설정/재설정 메일 (한국어, 관리 화면 주소) */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\StaffResetPassword($token));
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role',
        'vendor_id',
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected $attributes = ['role' => 'vendor'];

    public function vendor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** 플랫폼 회사 (공연·좌석티켓·입점업체·정산 관리) */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /** 입점업체 계정 (자기 업체 매장들만) */
    public function isVendor(): bool
    {
        return $this->role === 'vendor' && $this->vendor_id !== null;
    }
}
