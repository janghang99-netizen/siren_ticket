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
        // 문자 발송: 발송사가 정해지기 전까지는 로그로만 (SIREN_SMS_DRIVER=log)
        if (config('siren.sms_driver') === 'log') {
            $this->app->bind(\App\Services\Benefits\SmsSender::class, \App\Services\Benefits\LogSmsSender::class);
        }
        // 상품명 번역 (원본 다담다: Claude API) — 키는 ANTHROPIC_API_KEY 환경 변수
        $this->app->bind(\App\Services\Translation\ProductNameTranslator::class,
            fn () => new \App\Services\Translation\ClaudeProductNameTranslator(config('services.anthropic.key')));
        // 전국 공연장 DB (KOPIS 공연시설) — 키는 KOPIS_API_KEY
        $this->app->bind(\App\Services\KopisVenueImporter::class, fn () => new \App\Services\KopisVenueImporter(config('services.kopis.key')));
        // 좌석 배치도 이미지 판독 (Claude 비전) — 같은 키
        $this->app->bind(\App\Services\SeatMap\SeatMapReader::class,
            fn () => new \App\Services\SeatMap\ClaudeSeatMapReader(config('services.anthropic.key')));
    }

    public function boot(): void
    {
        // 모든 화면에서 $ticketing (공연 티켓 판매 기능을 쓰는지) — 메뉴·버튼을 숨기는 데 쓴다. 요청마다 한 번만 읽는다.
        \Illuminate\Support\Facades\View::composer('*', function ($view) {
            $attrs = request()->attributes;
            if (! $attrs->has('ticketing')) {
                $attrs->set('ticketing', \Illuminate\Support\Facades\Schema::hasTable('settings') && \App\Models\Setting::ticketingEnabled());
            }
            $view->with('ticketing', $attrs->get('ticketing'));
        });
        // 슈퍼어드민(플랫폼 회사): 공연·좌석티켓·입점업체·매장·정산
        Gate::define('super-admin', fn (User $u) => $u->isSuperAdmin());

        // 입점업체 콘솔: 심사 승인된 업체만 (심사중·반려·정지는 접근 불가)
        Gate::define('vendor', fn (User $u) => $u->isVendor() && $u->vendor?->isActive());

        // 입점업체 계정 자체 (심사 상태 화면 — 승인 전에도 볼 수 있음)
        Gate::define('vendor-account', fn (User $u) => $u->isVendor());
    }
}
