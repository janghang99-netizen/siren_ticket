<?php

use App\Http\Controllers\EisController;
use Illuminate\Support\Facades\Route;

// ── 비트EIS pull API (업체 단위, HMAC 서명) — CSRF 없는 api 그룹 ──
Route::post('/eis/orders', [EisController::class, 'orders'])->middleware('throttle:60,1');
Route::get('/eis/stock', [EisController::class, 'stock'])->middleware('throttle:120,1');

// 원본 다담다 주소 호환 (EIS 쪽 코드 변경 없이 도메인만 바꾸면 되게)
Route::get('/stock_export.php', [EisController::class, 'stock'])->middleware('throttle:120,1');
