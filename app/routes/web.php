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
    Route::post('/concerts/{event}/tickets', 'purchase')->middleware('throttle:20,1')->name('concerts.purchase');
    Route::get('/tickets', 'tickets')->name('tickets');
    Route::post('/tickets/{ticket}/cancel', 'cancelTicket')->name('tickets.cancel');

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

    // 약관·정책 (E1)
    Route::get('/policies/{doc}', 'policy')->whereIn('doc', ['terms', 'privacy', 'finance', 'refund'])->name('policy');

    // 매장 앞 픽업 전광판 (공개 — 픽업번호만 노출)
    Route::get('/display/{store}', 'display')->name('display');
    Route::get('/display/{store}/data', 'displayData')->name('display.data');
});

// ── 관리 화면 로그인 (슈퍼어드민 / 입점업체 공용) ──
Route::get('/login', [AuthController::class, 'form'])->middleware('guest')->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware(['guest', 'throttle:10,1']);
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

    Route::get('/products', 'products')->name('products');
    Route::get('/products/new', 'productCreate')->name('products.create');
    Route::post('/products', 'productStore')->name('products.store');
    Route::get('/products/{product}/edit', 'productEdit')->name('products.edit');
    Route::put('/products/{product}', 'productUpdate')->name('products.update');
    Route::patch('/products/{product}/toggle', 'toggleProduct')->name('products.toggle');

    // 생산·재고 (H)
    Route::get('/inventory', 'inventory')->name('inventory');
    Route::post('/inventory', 'inventoryRecord')->name('inventory.record');

    Route::get('/sales', 'sales')->name('sales');
    Route::get('/cancels', 'cancels')->name('cancels');
    Route::get('/settlements', 'settlements')->name('settlements');
    Route::get('/settlements/{settlement}', 'settlement')->name('settlements.show');
    Route::post('/settlements/{settlement}/dispute', 'dispute')->name('settlements.dispute');
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
