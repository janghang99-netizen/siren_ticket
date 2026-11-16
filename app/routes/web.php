<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FanController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\VendorApplicationController;
use Illuminate\Support\Facades\Route;

// ── 팬 (모바일) ──
Route::controller(FanController::class)->group(function () {
    Route::get('/', 'home')->name('home');
    Route::get('/concerts/{event}', 'concert')->name('concerts.show');
    // 티켓 예매는 회원만 (매장 음식 주문은 비회원 그대로)
    Route::post('/concerts/{event}/tickets', 'purchase')->middleware(['auth:member', 'throttle:20,1'])->name('concerts.purchase');
    Route::get('/concerts/{event}/sections/{section}/seats', 'seats')->whereNumber('section')->middleware('throttle:120,1')->name('concerts.seats');   // 좌석표 공연: 구역 좌석 현황
    Route::get('/tickets', 'tickets')->name('tickets');
    Route::post('/tickets/{ticket}/cancel', 'cancelTicket')->name('tickets.cancel');

    Route::get('/order', 'orderEntry')->name('order.entry');                      // 하단 '매장주문' 탭
    Route::get('/concerts/{event}/stores', 'stores')->name('stores.index');
    Route::get('/concerts/{event}/stores/{store}', 'menu')->name('stores.menu');
    Route::post('/concerts/{event}/stores/{store}/orders', 'placeOrder')->middleware('throttle:30,1')->name('stores.order');

    Route::get('/orders', 'myOrders')->name('orders.index');
    Route::get('/orders/{order}/pay', 'pay')->name('orders.pay');
    Route::post('/orders/{order}/pay', 'confirm')->name('orders.confirm');
    Route::get('/orders/{order}', 'order')->name('orders.show');
    Route::get('/orders/{order}/status', 'orderStatus')->name('orders.status');
    Route::post('/orders/{order}/cancel', 'cancelOrder')->name('orders.cancel');
    Route::post('/orders/{order}/cancel-request', 'requestCancel')->name('orders.cancel-request');

    // 내 혜택: 휴대폰 인증 → 마일리지·쿠폰·스탬프 (회원가입 없음)
    Route::get('/benefits', 'benefits')->name('benefits');
    Route::post('/benefits/send', 'sendCode')->middleware('throttle:6,1')->name('benefits.send');
    Route::post('/benefits/verify', 'verifyCode')->middleware('throttle:10,1')->name('benefits.verify');
    Route::post('/benefits/forget', 'forgetPhone')->name('benefits.forget');
    Route::post('/benefits/coupons/code', 'claimCode')->middleware('throttle:10,1')->name('benefits.code');
    Route::post('/benefits/coupons/{coupon}', 'claimCoupon')->middleware('throttle:20,1')->name('benefits.claim');

    // 약관·정책 (E1)
    Route::get('/policies/{doc}', 'policy')->whereIn('doc', ['terms', 'privacy', 'finance', 'refund'])->name('policy');

    // 매장 앞 픽업 전광판 (공개 — 픽업번호만 노출)
    Route::get('/display/{store}', 'display')->name('display');
    Route::get('/display/{store}/data', 'displayData')->name('display.data');
});

// ── 팬 회원: 가입 · 로그인 · 계정 보안 (관리 로그인과 별개) ──
Route::prefix('member')->name('member.')->controller(\App\Http\Controllers\MemberController::class)->group(function () {
    Route::middleware('guest:member')->group(function () {
        Route::get('/signup', 'signupForm')->name('signup');
        Route::post('/signup', 'signupStart')->middleware('throttle:5,1')->name('signup.start');
        Route::post('/signup/verify', 'signupVerify')->middleware('throttle:10,1')->name('signup.verify');
        Route::get('/login', 'loginForm')->name('login');
        Route::post('/login', 'login')->middleware('throttle:30,1')->name('login.attempt');
        Route::get('/login/verify', 'stepUpForm')->name('login.step-up');
        Route::post('/login/verify', 'stepUp')->middleware('throttle:10,1');
        Route::get('/login/two-factor', 'twoFactorForm')->name('login.two-factor');
        Route::post('/login/two-factor', 'twoFactor')->middleware('throttle:10,1');
        Route::get('/password/forgot', 'forgotForm')->name('password.forgot');
        Route::post('/password/forgot', 'forgotSend')->middleware('throttle:5,1')->name('password.send');
        Route::post('/password/reset', 'resetPassword')->middleware('throttle:10,1')->name('password.reset');
    });
    Route::middleware('auth:member')->group(function () {
        Route::get('/account', 'account')->name('account');
        Route::post('/logout', 'logout')->name('logout');
        Route::put('/password', 'changePassword')->middleware('throttle:5,1')->name('password.change');
        Route::put('/residence', 'updateResidence')->middleware('throttle:5,1')->name('residence');
        Route::post('/two-factor', 'twoFactorSetup')->name('two-factor.setup');
        Route::post('/two-factor/confirm', 'twoFactorConfirm')->middleware('throttle:10,1')->name('two-factor.confirm');
        Route::delete('/two-factor', 'twoFactorDisable')->middleware('throttle:5,1')->name('two-factor.disable');
        Route::delete('/devices/{device}', 'revokeDevice')->whereNumber('device')->name('devices.revoke');
        Route::post('/logout-everywhere', 'logoutEverywhere')->name('logout-everywhere');
    });
});

// ── 번역 (원본 다담다 방식): 상품명 AI 번역 사전 · 구글 오역 교정 사전 — 공개 JSON ──
// 상품 이미지 (storage/app/products)
Route::get('/media/seat-maps/{file}', [\App\Http\Controllers\SeatMapController::class, 'image'])
    ->where('file', '[A-Za-z0-9]{20,80}\.(jpg|jpeg|png|webp)')->name('media.seat-map');
Route::get('/media/products/{file}',[\App\Http\Controllers\ProductImageController::class, 'show'])
    ->where('file', '[A-Za-z0-9]{20,80}\.(jpg|jpeg|png|webp)')->name('media.product');
Route::get('/translate/dict/{lang}', [\App\Http\Controllers\TranslationController::class, 'dict'])->whereIn('lang', ['en', 'ja', 'zh'])->name('translate.dict');

// ── NFT 티켓: 공개 메타데이터(tokenURI)·이미지, 팬 입장 코드 ──
Route::get('/nft/tickets/{tokenId}', [\App\Http\Controllers\NftController::class, 'metadata'])->where('tokenId', '[0-9]+')->name('nft.metadata');
Route::get('/nft/tickets/{ticket}/image.svg', [\App\Http\Controllers\NftController::class, 'image'])->whereNumber('ticket')->name('nft.image');
Route::get('/tickets/{ticket}/entry-code', [\App\Http\Controllers\NftController::class, 'entryCode'])
    ->middleware(['auth:member', 'throttle:20,1'])->name('tickets.entry-code');

// ── 관리 화면 로그인 (슈퍼어드민 / 입점업체 공용) ──
// 이미 로그인해 있어도 로그인 화면을 연다 — 운영사 ↔ 입점업체 계정 바꾸기
Route::get('/login', [AuthController::class, 'form'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// ── 입점 신청 (A1) — 누구나 신청, 운영사 심사 후 승인 ──
Route::get('/partner/apply', [VendorApplicationController::class, 'form'])->middleware('guest')->name('partner.apply');
Route::post('/partner/apply', [VendorApplicationController::class, 'store'])->middleware(['guest', 'throttle:5,1']);
Route::get('/partner/status', [VendorApplicationController::class, 'status'])->middleware(['auth', 'can:vendor-account'])->name('partner.status');

// ── 입점업체 콘솔: 여러 매장의 사이렌오더 (승인된 업체만) ──
Route::prefix('partner')->name('partner.')->middleware(['auth', 'can:vendor'])->controller(PartnerController::class)->group(function () {
    Route::get('/', 'board')->name('board');
    Route::get('/board', 'boardData')->name('board.data');
    Route::post('/stores/{store:id}/switch', 'switchStore')->name('stores.switch');
    Route::get('/orders/{order}', 'order')->name('orders.show');
    Route::patch('/orders/{order}/status', 'updateStatus')->name('orders.status');
    Route::patch('/orders/{order}/cancel-request', 'respondCancel')->name('orders.cancel-request');
    Route::patch('/slots/{slot}/toggle', 'toggleSlot')->name('slots.toggle');
    Route::post('/pos-orders', 'posOrder')->name('pos');
    Route::get('/pos', 'pos')->name('pos.screen');                               // 현장판매 화면
    Route::get('/orders/{order}/ticket', 'ticket')->name('orders.ticket');       // 번호표·주방 주문서 인쇄

    Route::get('/products', 'products')->name('products');
    Route::get('/products/new', 'productCreate')->name('products.create');
    Route::post('/products', 'productStore')->name('products.store');
    Route::get('/products/{product}/edit', 'productEdit')->name('products.edit');
    Route::put('/products/{product}', 'productUpdate')->name('products.update');
    Route::patch('/products/{product}/toggle', 'toggleProduct')->name('products.toggle');
    Route::patch('/products/{product}/sold-out', 'toggleSoldOut')->name('products.sold-out');
    // 카테고리 (매장별)
    Route::post('/categories', 'categoryStore')->name('categories.store');
    Route::put('/categories/{category}', 'categoryUpdate')->name('categories.update');
    Route::patch('/categories/{category}/move', 'categoryMove')->name('categories.move');
    Route::delete('/categories/{category}', 'categoryDestroy')->name('categories.destroy');

    // 생산관리 (원본 production.php) — 오늘 생산량으로 재고 덮어쓰기 + EIS 생산 webhook
    Route::get('/production', 'production')->name('production');
    Route::post('/production', 'productionSave')->name('production.save');
    Route::put('/production/{id}', 'productionUpdate')->whereNumber('id')->name('production.update');
    Route::delete('/production/{id}', 'productionDelete')->whereNumber('id')->name('production.delete');

    // 생산·재고 (H)
    Route::get('/inventory', 'inventory')->name('inventory');
    Route::post('/inventory', 'inventoryRecord')->name('inventory.record');

    Route::get('/sales', 'sales')->name('sales');
    Route::get('/cancels', 'cancels')->name('cancels');
    Route::get('/settlements', 'settlements')->name('settlements');
    Route::get('/settlements/{settlement}', 'settlement')->name('settlements.show');
    Route::post('/settlements/{settlement}/dispute', 'dispute')->name('settlements.dispute');
});

// 입점업체 혜택: 우리 쿠폰(업체 부담) · 매장 스탬프
// 매장별 현장 카드 단말기 — 업체는 자기 매장 모두
Route::prefix('partner')->name('partner.')->middleware(['auth', 'can:vendor'])->controller(\App\Http\Controllers\TerminalController::class)->group(function () {
    Route::get('/terminals', 'index')->name('terminals');
    Route::post('/terminals', 'store')->name('terminals.store');
    Route::put('/terminals/{terminal}', 'update')->name('terminals.update');
    Route::patch('/terminals/{terminal}/toggle', 'toggle')->name('terminals.toggle');
    Route::delete('/terminals/{terminal}', 'destroy')->name('terminals.destroy');
});

Route::prefix('partner')->name('partner.')->middleware(['auth', 'can:vendor'])->controller(\App\Http\Controllers\PartnerBenefitController::class)->group(function () {
    Route::get('/coupons', 'coupons')->name('coupons');
    Route::get('/coupons/new', 'couponCreate')->name('coupons.create');
    Route::post('/coupons', 'couponStore')->name('coupons.store');
    Route::get('/coupons/{coupon}/edit', 'couponEdit')->name('coupons.edit');
    Route::put('/coupons/{coupon}', 'couponUpdate')->name('coupons.update');
    Route::get('/stamp', 'stamp')->name('stamp');
    Route::put('/stamp', 'stampSave')->name('stamp.save');
});

// 운영사 혜택: 쿠폰 · 고객 마일리지 · 관람 스탬프 · 혜택 원장
Route::prefix('admin')->name('admin.')->middleware(['auth', 'can:super-admin'])->controller(\App\Http\Controllers\BenefitAdminController::class)->group(function () {
    Route::get('/coupons', 'coupons')->name('coupons.index');
    Route::get('/coupons/new', 'couponCreate')->name('coupons.create');
    Route::post('/coupons', 'couponStore')->name('coupons.store');
    Route::get('/coupons/{coupon}', 'coupon')->whereNumber('coupon')->name('coupons.show');
    Route::get('/coupons/{coupon}/edit', 'couponEdit')->name('coupons.edit');
    Route::put('/coupons/{coupon}', 'couponUpdate')->name('coupons.update');
    Route::post('/coupons/{coupon}/grant', 'couponGrant')->name('coupons.grant');
    Route::put('/stamp', 'stampSave')->name('stamp.save');

    Route::get('/customers', 'customers')->name('customers.index');
    Route::get('/customers/{customer}', 'customer')->name('customers.show');
    Route::post('/customers/{customer}/mileage', 'customerAdjust')->name('customers.adjust');

    Route::get('/check-in', [\App\Http\Controllers\NftController::class, 'checkInForm'])->name('check-in');
    Route::post('/check-in', [\App\Http\Controllers\NftController::class, 'checkIn'])->middleware('throttle:120,1')->name('check-in.scan');

    // 번역 (원본 다담다 admin/translate_products.php) — 상품명 Claude 번역 · 구글 오역 교정 사전
    Route::controller(\App\Http\Controllers\TranslationController::class)->group(function () {
        Route::get('/translations', 'index')->name('translations');
        Route::post('/translations/test', 'test')->middleware('throttle:10,1')->name('translations.test');
        Route::post('/translations/run', 'run')->middleware('throttle:10,1')->name('translations.run');
        Route::post('/translations/fixes', 'fixStore')->name('translations.fixes.store');
        Route::delete('/translations/fixes/{fix}', 'fixDestroy')->name('translations.fixes.destroy');
    });

    Route::get('/ledger', 'ledger')->name('ledger');
    Route::post('/ledger/anchor', 'ledgerAnchor')->name('ledger.anchor');
});

// 원본 다담다 주소 호환: EIS 판매내역 pull (서명 검증, CSRF 제외)
Route::post('/admin/openmarket_export.php', [\App\Http\Controllers\EisController::class, 'orders'])
    ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)->middleware('throttle:60,1');

// ── 슈퍼어드민: 공연장 목록 ──
Route::prefix('admin/venues')->name('admin.venues.')->middleware(['auth', 'can:super-admin'])
    ->controller(\App\Http\Controllers\VenueController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('/catalog', 'catalog')->middleware('throttle:120,1')->name('catalog');                 // 전국 공연장 DB 검색
        Route::get('/catalog/{catalog}', 'catalogShow')->whereNumber('catalog')->middleware('throttle:60,1')->name('catalog.show');
        Route::get('/{venue}', 'show')->whereNumber('venue')->name('show');
        Route::put('/{venue}', 'update')->whereNumber('venue')->name('update');
        Route::delete('/{venue}', 'destroy')->whereNumber('venue')->name('destroy');
    });

// ── 슈퍼어드민: 공연장 좌석표 (배치도 이미지 → 등급·구역·좌석) ──
Route::prefix('admin/seat-maps')->name('admin.seat-maps.')->middleware(['auth', 'can:super-admin'])
    ->controller(\App\Http\Controllers\SeatMapController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/new', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/{seatMap}', 'edit')->whereNumber('seatMap')->name('edit');
        Route::put('/{seatMap}', 'update')->whereNumber('seatMap')->name('update');
        Route::post('/{seatMap}/image', 'replaceImage')->whereNumber('seatMap')->name('image');
        Route::post('/{seatMap}/read', 'reread')->whereNumber('seatMap')->middleware('throttle:10,1')->name('read');
        Route::delete('/{seatMap}', 'destroy')->whereNumber('seatMap')->name('destroy');
    });

// ── 슈퍼어드민 콘솔: 공연·좌석티켓·입점업체·매장·정산·설정 ──
Route::prefix('admin')->name('admin.')->middleware(['auth', 'can:super-admin'])->controller(AdminController::class)->group(function () {
    Route::get('/', fn () => redirect()->route('admin.events.index'));
    Route::get('/events', 'events')->name('events.index');
    Route::get('/events/new', 'eventCreate')->name('events.create');
    Route::post('/events', 'eventStore')->name('events.store');
    Route::get('/events/{event:id}/edit', 'eventEdit')->name('events.edit');
    Route::put('/events/{event:id}', 'eventUpdate')->name('events.update');
    Route::post('/events/{event:id}/cancel', 'eventCancel')->name('events.cancel');

    Route::get('/tickets', 'tickets')->name('tickets.index');
    Route::get('/tickets/{ticket}', 'ticket')->name('tickets.show');
    Route::patch('/tickets/{ticket}/cancel', 'ticketCancel')->name('tickets.cancel');

    Route::get('/vendors', 'vendors')->name('vendors.index');
    Route::get('/vendors/new', 'vendorCreate')->name('vendors.create');
    Route::post('/vendors', 'vendorStore')->name('vendors.store');
    Route::get('/vendors/{vendor}', 'vendor')->whereNumber('vendor')->name('vendors.show');
    Route::get('/vendors/{vendor}/edit', 'vendorEdit')->name('vendors.edit');
    Route::put('/vendors/{vendor}', 'vendorUpdate')->name('vendors.update');
    Route::patch('/vendors/{vendor}/state', 'vendorState')->name('vendors.state');
    Route::get('/vendors/{vendor}/documents/{type}', 'vendorDocument')->name('vendors.document');
    Route::post('/vendors/{vendor}/adjustments', 'adjustmentStore')->name('vendors.adjustments');
    Route::put('/vendors/{vendor}/integration', 'integrationSave')->name('vendors.integration');
    Route::post('/stores/{store:id}/terminals', [\App\Http\Controllers\TerminalController::class, 'store'])->name('stores.terminals');
    Route::put('/terminals/{terminal}', [\App\Http\Controllers\TerminalController::class, 'update'])->name('terminals.update');
    Route::patch('/terminals/{terminal}/toggle', [\App\Http\Controllers\TerminalController::class, 'toggle'])->name('terminals.toggle');
    Route::delete('/terminals/{terminal}', [\App\Http\Controllers\TerminalController::class, 'destroy'])->name('terminals.destroy');

    Route::get('/audit', 'audit')->name('audit');

    Route::get('/stores', 'stores')->name('stores.index');
    Route::get('/stores/new', 'storeCreate')->name('stores.create');
    Route::post('/stores', 'storeStore')->name('stores.store');
    Route::get('/stores/{store:id}', 'storeShow')->whereNumber('store')->name('stores.show');
    Route::get('/stores/{store:id}/edit', 'storeEdit')->name('stores.edit');
    Route::put('/stores/{store:id}', 'storeUpdate')->name('stores.update');
    Route::patch('/products/{product}/block', 'productBlock')->name('products.block');

    Route::get('/orders', 'orders')->name('orders.index');
    Route::get('/orders/{order}', 'order')->name('orders.show');
    Route::post('/orders/{order}/refund', 'orderRefund')->name('orders.refund');

    Route::get('/settlements', 'settlements')->name('settlements.index');
    Route::post('/settlements', 'settlementGenerate')->name('settlements.generate');
    Route::get('/settlements/{settlement}', 'settlement')->name('settlements.show');
    Route::patch('/settlements/{settlement}/pay', 'settlementPay')->name('settlements.pay');
    Route::patch('/settlements/{settlement}/tax-invoice', 'settlementTaxInvoice')->name('settlements.tax-invoice');
    Route::post('/settlements/{settlement}/reply', 'settlementReply')->name('settlements.reply');

    // 수수료·정산주기 설정 (C2·C3)
    Route::get('/settings', 'settings')->name('settings');
    Route::put('/settings', 'settingsSave')->name('settings.save');
});
