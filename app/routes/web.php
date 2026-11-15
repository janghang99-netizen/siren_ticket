<?php

use App\Http\Controllers\FanController;
use App\Http\Controllers\StaffController;
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

// ── 입점 매장 직원 ──
Route::prefix('staff')->name('staff.')->controller(StaffController::class)->group(function () {
    Route::get('/login', 'loginForm')->name('login');
    Route::post('/login', 'login')->middleware('throttle:10,1');

    Route::middleware('auth')->group(function () {
        Route::post('/logout', 'logout')->name('logout');
        Route::get('/', 'board')->name('board');
        Route::get('/board', 'boardData')->name('board.data');
        Route::patch('/orders/{order}/status', 'updateStatus')->name('orders.status');
        Route::patch('/slots/{slot}/toggle', 'toggleSlot')->name('slots.toggle');
        Route::post('/pos-orders', 'posOrder')->name('pos');
    });
});
