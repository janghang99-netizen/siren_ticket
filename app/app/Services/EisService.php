<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Store;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * 비트EIS 연동 — 원본 다담다 규격 그대로 (EIS 쪽은 주소만 바꾸면 된다).
 *  push  · 주문: OrderWebhookSender (order.completed, 수령완료 시 1회)
 *        · 생산: production.created / updated / deleted → store_webhooks.production_url  (원본 inc/webhook_production.php)
 *  pull  · 판매내역: POST {seller_id, from, to} + X-Dadamda-Signature(raw body)     (원본 admin/openmarket_export.php)
 *        · 현재재고: GET seller_id, ts, sig = HMAC(seller_id.ts), ±300초           (원본 api/stock_export.php)
 * seller_id = 매장 코드(stores.code). 서명 키 = store_webhooks.secret.
 */
class EisService
{
    public function __construct(private InventoryService $inventory) {}

    // ── 생산관리 (원본 production.php) ──

    /**
     * 생산 입력: 생산일자는 항상 오늘(원본 07-23 가드), 재고는 오늘 생산량으로 덮어쓴다(원본 07-06, 누적 금지).
     * 재고 변동은 원장에 남기고, EIS 로 production.created 전송.
     */
    public function recordProduction(Product $product, ?ProductOption $option, int $qty, ?string $memo, ?int $userId): int
    {
        if ($qty < 0) {
            throw new OrderException('생산 수량은 0 이상이어야 해요.');
        }
        $id = DB::transaction(function () use ($product, $option, $qty, $memo, $userId) {
            $id = DB::table('product_productions')->insertGetId([
                'store_id' => $product->store_id, 'product_id' => $product->id, 'product_option_id' => $option?->id,
                'production_date' => now()->toDateString(), 'qty' => $qty, 'memo' => $memo, 'user_id' => $userId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->inventory->record($product, $option, 'adjust', $qty, '생산 입력 #'.$id.($memo ? ' · '.$memo : ''), $userId);

            return $id;
        });
        $this->sendProduction($id, 'production.created');

        return $id;
    }

    /** 생산 기록 수정: 오늘 기록이면 재고도 새 수량으로 맞춘다. EIS 로 production.updated */
    public function updateProduction(int $id, int $qty, ?string $memo, ?int $userId): void
    {
        $row = DB::table('product_productions')->find($id) ?? throw new OrderException('생산 기록이 없어요.');
        DB::transaction(function () use ($row, $qty, $memo, $userId) {
            DB::table('product_productions')->where('id', $row->id)->update(['qty' => $qty, 'memo' => $memo, 'updated_at' => now()]);
            if (substr((string) $row->production_date, 0, 10) === now()->toDateString()) {
                $this->inventory->record(Product::find($row->product_id), $row->product_option_id ? ProductOption::find($row->product_option_id) : null,
                    'adjust', $qty, '생산 수정 #'.$row->id, $userId);
            }
        });
        $this->sendProduction($row->id, 'production.updated');
    }

    /** 생산 기록 삭제 (재고는 그대로). 행이 사라지기 전에 읽어 production.deleted 전송 */
    public function deleteProduction(int $id): void
    {
        $row = DB::table('product_productions')->find($id) ?? throw new OrderException('생산 기록이 없어요.');
        $payload = $this->productionPayload($row, 'production.deleted');
        DB::table('product_productions')->where('id', $id)->delete();
        $this->postProduction($row->store_id, $row->id, 'production.deleted', $payload);
    }

    private function sendProduction(int $id, string $event): void
    {
        $row = DB::table('product_productions')->find($id);
        if ($this->postProduction($row->store_id, $id, $event, $this->productionPayload($row, $event))) {
            DB::table('product_productions')->where('id', $id)->update(['webhook_sent' => true]);
        }
    }

    private function productionPayload(object $row, string $event): array
    {
        $product = Product::find($row->product_id);
        $option = $row->product_option_id ? ProductOption::find($row->product_option_id) : null;
        $current = $option && $option->stock !== null ? $option->stock : $product?->stock;
        $store = Store::find($row->store_id);

        return ['productions' => [[
            'event' => $event,
            'production_no' => (string) $row->id,
            'seller_id' => OrderWebhookSender::integration($store?->vendor_id)?->eis_code ?? $store?->code,
            'store_code' => $store?->code,
            'product_number' => (string) $row->product_id,
            'product_name' => $product?->name,
            'option_title' => $option?->name ?? '없음',
            'production_date' => substr((string) $row->production_date, 0, 10),
            'qty' => (int) $row->qty,
            'current_stock' => (int) ($current ?? 0),
            'memo' => (string) $row->memo,
            'created_at' => (string) $row->created_at,
        ]]];
    }

    /** 실패해도 생산 저장은 막지 않는다 (원본과 동일 — 로그만) */
    private function postProduction(int $storeId, int $refId, string $event, array $payload): bool
    {
        $hook = OrderWebhookSender::integration(\App\Models\Store::whereKey($storeId)->value('vendor_id'));
        if (! $hook || ! $hook->is_active || $hook->production_url === '') {
            $this->log($storeId, $event, $refId, null, false, 'SKIP (no production_url)', $payload);

            return false;
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        try {
            $res = Http::timeout(config('siren.webhook_timeout'))->connectTimeout(3)
                ->withHeaders(['X-Dadamda-Event' => $event, 'X-Dadamda-Signature' => 'sha256='.hash_hmac('sha256', $body, $hook->secret)])
                ->withBody($body, 'application/json')->post($hook->production_url);
            $ok = $res->successful();
            $this->log($storeId, $event, $refId, $hook->production_url, $ok, ($ok ? 'OK' : 'FAIL')." (HTTP {$res->status()})", $payload);

            return $ok;
        } catch (ConnectionException) {
            $this->log($storeId, $event, $refId, $hook->production_url, false, 'FAIL (timeout/connection refused)', $payload);

            return false;
        }
    }

    private function log(?int $storeId, string $type, ?int $refId, ?string $url, bool $ok, string $result, ?array $payload): void
    {
        DB::table('integration_logs')->insert([
            'vendor_id' => Store::whereKey($storeId)->value('vendor_id'), 'store_id' => $storeId, 'type' => $type, 'ref_id' => $refId, 'url' => $url, 'ok' => $ok, 'result' => $result,
            'payload' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── pull API 공통 (업체 단위) ──

    /** @return array{0:\App\Models\Vendor,1:string,2:bool}|null EIS 코드(seller_id) → 업체, 서명 키, 사용 여부 */
    public function vendorBySeller(string $sellerId): ?array
    {
        $hook = DB::table('vendor_integrations')->where('eis_code', $sellerId)->first();
        $vendor = $hook ? \App\Models\Vendor::find($hook->vendor_id) : null;

        return $vendor && $hook->secret !== '' ? [$vendor, $hook->secret, (bool) $hook->is_active] : null;
    }

    /** 원본 openmarket_export: 업체의 모든 매장 수령완료(5)·정산완료(6) 주문, 주문 webhook 과 같은 형식 */
    public function exportOrders(\App\Models\Vendor $vendor, string $sellerId, ?string $from, ?string $to): array
    {
        $orders = Order::with(['items', 'store'])->whereIn('store_id', $vendor->stores()->pluck('id'))->whereIn('status', [5, 6])
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->orderBy('id')->get();

        return ['orders' => $orders->flatMap(fn (Order $o) => OrderWebhookSender::payloadRows($o, $sellerId))->values()->all()];
    }

    /** 원본 stock_export: 업체의 모든 매장, 판매 중 상품의 옵션별 현재고 (null = 재고 관리 안 함) */
    public function exportStock(\App\Models\Vendor $vendor, string $sellerId): array
    {
        $items = Product::with(['options', 'store'])->sellable()->whereIn('store_id', $vendor->stores()->pluck('id'))->orderBy('store_id')->orderBy('sort')->get()
            ->flatMap(fn (Product $p) => $p->options->isEmpty()
                ? [['store_code' => $p->store->code, 'product_number' => (string) $p->id, 'product_name' => $p->name, 'option_title' => '없음', 'current_stock' => $p->stock]]
                : $p->options->map(fn ($o) => ['store_code' => $p->store->code, 'product_number' => (string) $p->id, 'product_name' => $p->name, 'option_title' => $o->name, 'current_stock' => $o->stock ?? $p->stock])->all());

        return ['ok' => true, 'seller_id' => $sellerId, 'generated_at' => now()->format('Y-m-d H:i:s'), 'count' => $items->count(), 'items' => $items->values()->all()];
    }
}
