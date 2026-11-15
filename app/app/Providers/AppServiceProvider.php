<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\Services\Benefits\BlockchainAnchor::class, fn ($app) => $app->make(config('siren.blockchain_anchor')));
        $this->app->bind(\App\Services\Nft\TicketChain::class, fn ($app) => $app->make(config('siren.nft.chain')));
    }

    public function boot(): void
    {
        // 슈퍼어드민(플랫폼 회사): 공연·좌석티켓·입점업체·매장·정산
        Gate::define('super-admin', fn (User $u) => $u->isSuperAdmin());

        // 입점업체 콘솔: 심사 승인된 업체만 (심사중·반려·정지는 접근 불가)
        Gate::define('vendor', fn (User $u) => $u->isVendor() && $u->vendor?->isActive());

        // 입점업체 계정 자체 (심사 상태 화면 — 승인 전에도 볼 수 있음)
        Gate::define('vendor-account', fn (User $u) => $u->isVendor());
    }
}
