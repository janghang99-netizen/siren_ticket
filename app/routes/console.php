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

// 공연이 끝난 티켓에 관람 마일리지·스탬프 지급 (공연 시작 3시간 뒤부터)
Artisan::command('benefits:grant-attendance', function (\App\Services\Benefits\BenefitService $benefits) {
    $this->info('관람 혜택 지급: '.$benefits->grantAttendance().'매');
})->purpose('관람이 끝난 티켓에 마일리지와 관람 스탬프를 준다');

\Illuminate\Support\Facades\Schedule::command('benefits:grant-attendance')->hourly();

// 혜택 원장: 해시 체인 검증 + 머클 루트 앵커 (체인이 정해지면 그 체인에 기록)
Artisan::command('ledger:anchor', function (\App\Services\Benefits\BenefitLedgerService $ledger) {
    $check = $ledger->verify();
    if (! $check['ok']) {
        $this->error("원장 위변조 의심: #{$check['broken_at']}번 기록부터 체인이 끊겼어요. 앵커를 만들지 않아요.");
        report(new RuntimeException("benefit_ledger chain broken at {$check['broken_at']}"));

        return 1;
    }
    $anchor = $ledger->anchor();
    $this->info($anchor ? "앵커 #{$anchor->id}: {$anchor->row_count}건 · {$anchor->merkle_root}" : '새 기록 없음');
})->purpose('혜택 원장을 검증하고 머클 루트로 묶는다');

\Illuminate\Support\Facades\Schedule::command('ledger:anchor')->dailyAt('03:00');
