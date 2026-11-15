<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 미결제 주문 만료 → 캐파·재고 반환 (매분 실행: php artisan schedule:work)
Artisan::command('orders:expire-pending', function (\App\Services\OrderService $orders) {
    $this->info('만료: '.$orders->expirePending().'건');
})->purpose('결제 안 된 사이렌오더를 취소하고 캐파·재고를 돌려준다');

\Illuminate\Support\Facades\Schedule::command('orders:expire-pending')->everyMinute();

// 업체별 정산주기에 따라 자동 정산 (오늘이 정산일인 업체만, 직전 정산 다음 날 ~ 어제)
Artisan::command('settlements:run-due', function (\App\Services\SettlementService $settlements) {
    $made = $settlements->runDue();
    $this->info("정산서 {$made->count()}건 생성");
})->purpose('정산주기가 도래한 업체의 정산서를 만든다');

\Illuminate\Support\Facades\Schedule::command('settlements:run-due')->dailyAt('06:00');
