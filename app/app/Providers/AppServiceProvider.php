<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // 슈퍼어드민(플랫폼 회사): 공연·좌석티켓·입점업체·매장·정산
        Gate::define('super-admin', fn (User $u) => $u->isSuperAdmin());

        // 입점업체: 자기 업체 매장들의 사이렌오더 (정지된 업체는 접근 불가)
        Gate::define('vendor', fn (User $u) => $u->isVendor() && $u->vendor?->isActive());
    }
}
