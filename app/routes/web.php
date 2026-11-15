<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FanController;
use App\Http\Controllers\PartnerController;
use Illuminate\Support\Facades\Route;

// ── 팬 (모바일) ──
Route::controller(FanController::class)->group(function () {
    Route::get('/', 'home')->name('home');
    Route::get('/concerts/{event}', 'concert')->name('concerts.show');
    Route::post('/concerts/{event}/tickets', 'purchase')->middleware('throttle:20,1')->name('concerts.purchase');
    Route::get('/tickets', 'tickets')->name('tickets');

    Route::get('/concerts/{event}/stores', 'stores')->name('stores.index');
    Route::get('/concerts/{event}/stores/{store}', 'menu')->name('stores.menu');
    Route::post('/concerts/{event}/stores/{store}/orders', 'placeOrder')->middleware('throttle:30,1')->name('stores.order');

    Route::get('/orders', 'myOrders')->name('orders.index');
    Route::get('/orders/{order}/pay', 'pay')->name('orders.pay');
    Route::post('/orders/{order}/pay', 'confirm')->name('orders.confirm');
    Route::get('/orders/{order}', 'order')->name('orders.show');
    Route::get('/orders/{order}/status', 'orderStatus')->name('orders.status');

    // 매장 앞 픽업 전광판 (공개 — 픽업번호만 노출)
    Route::get('/display/{store}', 'display')->name('display');
    Route::get('/display/{store}/data', 'displayData')->name('display.data');
});

// ── 관리 화면 로그인 (슈퍼어드민 / 입점업체 공용) ──
Route::get('/login', [AuthController::class, 'form'])->middleware('guest')->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware(['guest', 'throttle:10,1']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// ── 입점업체 콘솔: 여러 매장의 사이렌오더 ──
Route::prefix('partner')->name('partner.')->middleware(['auth', 'can:vendor'])->controller(PartnerController::class)->group(function () {
    Route::get('/', 'board')->name('board');
    Route::get('/board', 'boardData')->name('board.data');
    Route::post('/stores/{store:id}/switch', 'switchStore')->name('stores.switch');
    Route::patch('/orders/{order}/status', 'updateStatus')->name('orders.status');
    Route::patch('/slots/{slot}/toggle', 'toggleSlot')->name('slots.toggle');
    Route::post('/pos-orders', 'posOrder')->name('pos');

    Route::get('/products', 'products')->name('products');
    Route::get('/products/new', 'productCreate')->name('products.create');
    Route::post('/products', 'productStore')->name('products.store');
    Route::get('/products/{product}/edit', 'productEdit')->name('products.edit');
    Route::put('/products/{product}', 'productUpdate')->name('products.update');
    Route::patch('/products/{product}/toggle', 'toggleProduct')->name('products.toggle');

    Route::get('/sales', 'sales')->name('sales');
    Route::get('/cancels', 'cancels')->name('cancels');
    Route::get('/settlements', 'settlements')->name('settlements');
    Route::get('/settlements/{settlement}', 'settlement')->name('settlements.show');
});

// ── 슈퍼어드민 콘솔: 공연·좌석티켓·입점업체·매장·정산 ──
Route::prefix('admin')->name('admin.')->middleware(['auth', 'can:super-admin'])->controller(AdminController::class)->group(function () {
    Route::get('/', fn () => redirect()->route('admin.events.index'));
    Route::get('/events', 'events')->name('events.index');
    Route::get('/events/new', 'eventCreate')->name('events.create');
    Route::post('/events', 'eventStore')->name('events.store');
    Route::get('/events/{event:id}/edit', 'eventEdit')->name('events.edit');
    Route::put('/events/{event:id}', 'eventUpdate')->name('events.update');

    Route::get('/tickets', 'tickets')->name('tickets.index');
    Route::patch('/tickets/{ticket}/cancel', 'ticketCancel')->name('tickets.cancel');

    Route::get('/vendors', 'vendors')->name('vendors.index');
    Route::get('/vendors/new', 'vendorCreate')->name('vendors.create');
    Route::post('/vendors', 'vendorStore')->name('vendors.store');
    Route::get('/vendors/{vendor}/edit', 'vendorEdit')->name('vendors.edit');
    Route::put('/vendors/{vendor}', 'vendorUpdate')->name('vendors.update');

    Route::get('/stores', 'stores')->name('stores.index');
    Route::get('/stores/new', 'storeCreate')->name('stores.create');
    Route::post('/stores', 'storeStore')->name('stores.store');
    Route::get('/stores/{store:id}/edit', 'storeEdit')->name('stores.edit');
    Route::put('/stores/{store:id}', 'storeUpdate')->name('stores.update');

    Route::get('/settlements', 'settlements')->name('settlements.index');
    Route::post('/settlements', 'settlementGenerate')->name('settlements.generate');
    Route::get('/settlements/{settlement}', 'settlement')->name('settlements.show');
    Route::patch('/settlements/{settlement}/pay', 'settlementPay')->name('settlements.pay');

    Route::get('/orders', 'orders')->name('orders.index');
});
