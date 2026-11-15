<?php

namespace App\Http\Controllers;

use App\Services\EisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EIS 가 호출하는 pull API — 원본 규격 그대로, 업체(EIS 코드 = seller_id) 단위.
 *  POST /api/eis/orders  (원본 /admin/openmarket_export.php)  body {"seller_id","from","to"}, X-Dadamda-Signature: sha256=HMAC(raw body)
 *  GET  /api/eis/stock   (원본 /api/stock_export.php)         ?seller_id&ts&sig, sig = HMAC(seller_id.ts), ±300초
 */
class EisController extends Controller
{
    public function orders(Request $request, EisService $eis): JsonResponse
    {
        $raw = $request->getContent();
        $in = json_decode($raw, true);
        $sellerId = is_array($in) ? (string) ($in['seller_id'] ?? '') : '';
        if ($sellerId === '') {
            return response()->json(['error' => 'seller_id required'], 400);
        }
        [$vendor, $secret, $active] = $eis->vendorBySeller($sellerId) ?? [null, null, false];
        if (! $vendor || ! $active) {
            return response()->json(['error' => 'unknown seller'], 404);
        }
        $expected = 'sha256='.hash_hmac('sha256', $raw, $secret);
        if (! hash_equals($expected, (string) $request->header('X-Dadamda-Signature'))) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        return response()->json($eis->exportOrders($vendor, $sellerId, $in['from'] ?? null, $in['to'] ?? null), 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function stock(Request $request, EisService $eis): JsonResponse
    {
        $sellerId = (string) $request->query('seller_id', '');
        $ts = (int) $request->query('ts', 0);
        [$vendor, $secret, $active] = $eis->vendorBySeller($sellerId) ?? [null, null, false];
        if (! $vendor || ! $active) {
            return response()->json(['ok' => false, 'error' => 'unknown seller'], 404);
        }
        if (abs(time() - $ts) > 300) {
            return response()->json(['ok' => false, 'error' => 'expired ts'], 401);   // 재사용 공격 방지 (±5분)
        }
        if (! hash_equals(hash_hmac('sha256', $sellerId.'.'.$ts, $secret), strtolower((string) $request->query('sig', '')))) {
            return response()->json(['ok' => false, 'error' => 'invalid signature'], 401);
        }

        return response()->json($eis->exportStock($vendor, $sellerId), 200, [], JSON_UNESCAPED_UNICODE);
    }
}
