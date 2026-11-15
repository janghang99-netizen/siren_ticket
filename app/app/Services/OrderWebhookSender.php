<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * 수령완료 주문을 매장 ERP(비트EIS)로 push. 원본 inc/webhook_order.php send_order_webhook() 호환.
 * - 주문당 1회 (webhook_sent_at 원자적 선점 → 버튼 재클릭/동시요청에도 중복전송 없음)
 * - 재전송 없음: 실패는 webhook_deliveries 에 남기고 주문은 막지 않는다.
 */
class OrderWebhookSender
{
    public function sendOnce(Order $order): void
    {
        $claimed = Order::whereKey($order->id)->whereNull('webhook_sent_at')->update(['webhook_sent_at' => now()]);
        if ($claimed === 0) {
            return;
        }

        $hook = DB::table('store_webhooks')->where('store_id', $order->store_id)->where('is_active', true)->first();
        if (! $hook) {
            $this->log($order, null, false, 'FAIL (no config)', null);

            return;
        }

        $order->loadMissing(['items', 'store']);
        $payload = ['orders' => $order->items->map(fn ($i) => [
            'event' => 'order.completed',
            'order_no' => $order->order_no,
            'ordered_at' => $order->created_at->format('Y-m-d H:i:s'),
            'seller_id' => $order->store->code,
            'product_number' => (string) $i->product_id,
            'product_name' => $i->name,
            'qty' => $i->qty,
            'options' => (string) $i->option_name,
            'unit_price' => $i->unit_price,
            'add_price' => 0,
            'line_total' => $i->line_total,
            'total_amount' => $order->total_amount,
        ])->values()->all()];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $signature = 'sha256='.hash_hmac('sha256', $body, $hook->secret);

        try {
            $res = Http::timeout(config('siren.webhook_timeout'))
                ->withHeaders(['X-Dadamda-Signature' => $signature])
                ->withBody($body, 'application/json')
                ->post($hook->url);
            $this->log($order, $hook->url, $res->successful(), ($res->successful() ? 'OK' : 'FAIL')." (HTTP {$res->status()})", $payload);
        } catch (ConnectionException $e) {
            $this->log($order, $hook->url, false, 'FAIL (timeout/connection refused)', $payload);
        }
    }

    private function log(Order $order, ?string $url, bool $ok, string $result, ?array $payload): void
    {
        DB::table('webhook_deliveries')->insert([
            'order_id' => $order->id, 'url' => $url, 'ok' => $ok, 'result' => $result,
            'payload' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
