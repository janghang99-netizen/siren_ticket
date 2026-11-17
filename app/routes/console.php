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

// 전국 공연장 DB: KOPIS 공연시설 목록 받기 (--details 면 시설마다 주소·객석·홀까지, 수천 건이라 오래 걸림)
Artisan::command('venues:import-kopis {--details : 시설마다 상세(주소·객석·홀)까지 받기}', function (\App\Services\KopisVenueImporter $kopis) {
    if (! $kopis->ready()) {
        $this->warn('KOPIS_API_KEY 가 없어요. kopis.or.kr 에서 서비스 키를 발급받아 서버 환경 변수에 넣어 주세요.');

        return 0;
    }
    try {
        $r = $kopis->importList(fn ($page, $n) => $this->line("  {$page}쪽 · {$n}곳"));
        $this->info("공연시설 새로 {$r['added']}곳 · 갱신 {$r['updated']}곳 · 기본 목록 정리 {$r['removed']}곳");
        if ($this->option('details')) {
            $todo = \App\Models\VenueCatalog::where('source', 'kopis')->whereNull('address')->get();
            $bar = $this->output->createProgressBar($todo->count());
            foreach ($todo as $row) {
                $kopis->fillDetail($row);
                $bar->advance();
                usleep(150_000);   // KOPIS 서버 부담 줄이기
            }
            $bar->finish();
            $this->newLine();
        }
    } catch (\RuntimeException $e) {
        $this->error($e->getMessage());

        return 1;
    }

    return 0;
})->purpose('KOPIS 공연시설 목록으로 전국 공연장 DB 를 채운다');

\Illuminate\Support\Facades\Schedule::command('venues:import-kopis')->monthlyOn(1, '04:00');

// 다담다 이관: 원본 관리자 화면에서 읽은 상품 JSON → 한 판매자 상품만 새 매장으로 (다시 돌려도 같은 상품을 고친다)
Artisan::command('dadamda:import-products
    {file=database/import/dadamda/arena1_products.json : 원본 상품 JSON}
    {--seller=arena1 : 이 원본 판매자 아이디의 상품만}
    {--store= : 넣을 매장 코드 (없으면 원본 브랜드 이름으로 새 업체·매장을 만든다, 기본 = 판매자 아이디)}
    {--images : 원본 사진도 받아 저장}', function (\App\Services\DadamdaProductImporter $importer) {
    $path = $this->argument('file');
    $path = is_file($path) ? $path : base_path($path);
    $items = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (! is_array($items)) {
        $this->error("JSON 파일을 읽지 못했어요: {$this->argument('file')}");

        return 1;
    }
    $seller = (string) $this->option('seller');
    $items = array_values(array_filter($items, fn ($p) => ($p['seller'] ?? null) === $seller));
    if (! $items) {
        $this->warn("판매자 {$seller} 의 상품이 파일에 없어요.");

        return 1;
    }

    $code = (string) ($this->option('store') ?: $seller);
    $store = \App\Models\Store::where('code', $code)->first();
    if (! $store) {
        // 가장 많이 쓴 브랜드 이름으로 업체·매장을 만든다 (주문은 운영사가 매장을 열어야 받는다)
        $brand = collect($items)->countBy('brand')->sortDesc()->keys()->first() ?: $seller;
        $store = \Illuminate\Support\Facades\DB::transaction(function () use ($brand, $code) {
            $vendor = \App\Models\Vendor::create(['name' => $brand, 'state' => 'approved']);

            return \App\Models\Store::create(['vendor_id' => $vendor->id, 'code' => $code, 'name' => $brand, 'is_open' => false]);
        });
        $this->info("새 매장을 만들었어요: {$store->name} ({$code}) — 운영사 화면에서 매장 정보를 채우고 열어 주세요.");
    }

    $r = $importer->import($items, $store, (bool) $this->option('images'));
    $this->info("{$store->name}: 새로 {$r['created']}개 · 갱신 {$r['updated']}개 · 사진 {$r['images']}장");
    foreach ($r['errors'] as $e) {
        $this->warn('  '.$e);
    }

    return 0;
})->purpose('원본 다담다 상품을 판매자 하나씩 새 매장으로 가져온다');

// 다담다 이관: 판매(주문) 기록 — 상품 이관 다음에. 구매자 정보 없이, 새 자동 정산에는 넣지 않는다
Artisan::command('dadamda:import-orders
    {file=database/import/dadamda/arena1_orders.json : 원본 주문 JSON}
    {--store=arena1 : 넣을 매장 코드 (상품을 넣은 매장)}', function (\App\Services\DadamdaOrderImporter $importer) {
    $path = $this->argument('file');
    $path = is_file($path) ? $path : base_path($path);
    $orders = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (! is_array($orders)) {
        $this->error("JSON 파일을 읽지 못했어요: {$this->argument('file')}");

        return 1;
    }
    $store = \App\Models\Store::where('code', (string) $this->option('store'))->first();
    if (! $store) {
        $this->error("매장 {$this->option('store')} 이(가) 없어요. 먼저 dadamda:import-products 로 상품을 넣어 주세요.");

        return 1;
    }

    $r = $importer->import($orders, $store);
    $this->info("{$store->name}: 주문 {$r['created']}건 · ".number_format($r['total']).'원 가져옴'
        ." · 이미 있음 {$r['skipped_existing']}건 · 입금대기(미결제) 제외 {$r['skipped_unpaid']}건");
    if ($r['status_updated']) {
        $this->line("  원본에서 상태가 바뀐 주문 {$r['status_updated']}건 갱신 (예: 수령완료 → 정산완료)");
    }
    foreach ($r['placeholders'] as $p) {
        $this->line("  원본에서 지워진 상품 → 숨김 상품으로 만듦: {$p}");
    }
    if ($r['cancel_requests']) {
        $this->warn('  원본 구매취소요청 → 판매취소로 넣음 (원본에서 확인 필요): '.implode(', ', $r['cancel_requests']));
    }
    foreach ($r['errors'] as $e) {
        $this->warn('  '.$e);
    }

    return 0;
})->purpose('원본 다담다 판매 기록을 새 매장 주문으로 가져온다');

// 다담다 이관: 정산 기록 — 판매 이관 다음에. 원본 금액과 1원이라도 다르면 넣지 않는다
Artisan::command('dadamda:import-settlements
    {file=database/import/dadamda/arena1_settlements.json : 원본 정산 JSON}
    {--store=arena1 : 상품·판매를 넣은 매장 코드}', function (\App\Services\DadamdaSettlementImporter $importer) {
    $path = $this->argument('file');
    $path = is_file($path) ? $path : base_path($path);
    $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (! is_array($data)) {
        $this->error("JSON 파일을 읽지 못했어요: {$this->argument('file')}");

        return 1;
    }
    $store = \App\Models\Store::where('code', (string) $this->option('store'))->first();
    if (! $store) {
        $this->error("매장 {$this->option('store')} 이(가) 없어요. 상품·판매를 먼저 가져와 주세요.");

        return 1;
    }

    $r = $importer->import($data, $store);
    $this->info("{$store->name}: 정산서 {$r['created']}장 · 묶은 주문 {$r['linked_orders']}건 · 이미 있음 {$r['skipped_existing']}장");
    if ($r['vendor_filled']) {
        $this->line('  업체 사업자 정보 채움: '.implode(', ', $r['vendor_filled']).' (계좌는 운영사 화면에서 입력)');
    }
    if ($p = $r['pending']) {
        $same = $p['expected'] === $p['actual'] ? '일치' : '불일치';
        $this->line('  원본 정산 대기 '.number_format($p['expected']).'원 ↔ 옮긴 수령완료 '.number_format($p['actual'])."원 ({$p['orders']}건) — {$same}. 이 주문들은 새 자동 정산에 들어가지 않아요.");
    }
    foreach ($r['errors'] as $e) {
        $this->warn('  '.$e);
    }

    return $r['errors'] ? 1 : 0;
})->purpose('원본 다담다 정산 기록을 새 정산서로 가져온다');

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

// 상품명 번역 (원본 다담다: Claude API) — 새 상품·이름 바뀐 상품을 언어마다 최대 150개씩
Artisan::command('translations:products {--lang=*}', function (\App\Services\Translation\ProductTranslationService $tr) {
    if (! $tr->ready()) {
        $this->warn('ANTHROPIC_API_KEY 가 없어 건너뜀 — 상품명은 구글 번역이 맡아요.');

        return;
    }
    foreach ($this->option('lang') ?: array_keys(\App\Services\Translation\ProductTranslationService::LANGS) as $lang) {
        $r = $tr->run($lang);
        $this->info("{$lang}: {$r['translated']}개 번역".($r['errors'] ? ' · 실패 '.implode(' / ', $r['errors']) : ''));
    }
})->purpose('번역 대기 상품명을 Claude 로 번역해 사전에 넣는다');

\Illuminate\Support\Facades\Schedule::command('translations:products')->hourly()->withoutOverlapping();
