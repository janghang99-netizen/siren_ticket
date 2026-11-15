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

// ── NFT 티켓 · 카이아 ──
// 운영사 키 만들기 (storage/app/.kaia_operator_key, 권한 600). 화면에는 주소만 — 개인키는 출력하지 않는다.
Artisan::command('nft:operator', function () {
    $file = config('siren.nft.kaia.operator_key_file');
    $signer = app(\App\Services\Nft\Evm\EvmSigner::class);
    if (! is_file($file)) {
        $key = $signer->newKey();
        file_put_contents($file, $key['private']);
        @chmod($file, 0600);
        $this->info('운영사 키를 새로 만들었어요.');
    }
    $this->line('운영사 주소: '.$signer->address(trim(file_get_contents($file))));
    $this->line('테스트넷이면 이 주소로 Kairos 수도꼭지(faucet)에서 테스트 KAIA를 받아 주세요.');
})->purpose('카이아 운영사 키를 만들고 주소를 보여 준다');

Artisan::command('nft:status', function () {
    $kaia = app(\App\Services\Nft\KaiaTicketChain::class);
    $this->line('체인 설정: '.config('siren.nft.chain'));
    $this->line('RPC: '.config('siren.nft.kaia.rpc').' · 체인 ID '.$kaia->chainId());
    $addr = $kaia->operatorAddress();
    $wei = \App\Services\Nft\Evm\Hex::strip($kaia->balance($addr));
    $this->line("운영사 {$addr} · 잔액 ".rtrim(rtrim(number_format(hexdec($wei) / 1e18, 6, '.', ''), '0'), '.').' KAIA');
    $this->line('컨트랙트: '.($kaia->contract() ?: '(아직 없음 — nft:deploy)'));
    $this->line('대기·오류 토큰: '.\App\Models\TicketToken::where('status', 'pending')->orWhereNotNull('last_error')->count().'건');
})->purpose('카이아 연결·운영사 잔액·컨트랙트 상태');

// SirenTicket 컨트랙트 배포 → 주소를 설정(settings)에 저장
Artisan::command('nft:deploy {--base-uri=}', function () {
    $kaia = app(\App\Services\Nft\KaiaTicketChain::class);
    $artifact = json_decode(file_get_contents(base_path('blockchain/build/SirenTicket.json')), true);
    $base = $this->option('base-uri') ?: rtrim(config('app.url'), '/').'/nft/tickets/';
    $tx = $kaia->deploy($artifact['bytecode'], $base);
    $this->line("배포 트랜잭션: {$tx}");
    for ($i = 0; $i < 30; $i++) {
        sleep(2);
        $r = $kaia->receipt($tx);
        if ($r) {
            if (($r['status'] ?? '0x0') !== '0x1') {
                $this->error('배포 실패 (status '.$r['status'].')');

                return 1;
            }
            \App\Models\Setting::put(['kaia_contract_'.$kaia->network() => $r['contractAddress']]);
            $this->info('컨트랙트 주소: '.$r['contractAddress']);
            $this->line(config('siren.nft.kaia.explorer').'/address/'.$r['contractAddress']);

            return 0;
        }
    }
    $this->warn('아직 블록에 안 들어갔어요. 잠시 뒤 영수증을 확인해 주세요.');
})->purpose('카이아에 SirenTicket(양도 불가 NFT) 컨트랙트를 배포한다');

// 체인 전송 실패분 재시도 (발행·소각·입장 표시)
Artisan::command('nft:retry', function (\App\Services\Nft\NftTicketService $nft) {
    $d = $nft->retryPending();
    $this->info("재전송 — 발행 {$d['mint']} · 소각 {$d['burn']} · 입장 {$d['used']}");
})->purpose('체인에 못 보낸 NFT 발행·소각·입장 기록을 다시 보낸다');

\Illuminate\Support\Facades\Schedule::command('nft:retry')->everyFiveMinutes()->withoutOverlapping();
