<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * 수령완료 주문을 업체의 EIS 로 push. 원본 inc/webhook_order.php send_order_webhook() 호환.
 * - 업체 단위 연동(vendor_integrations): 그 업체의 어느 매장 주문이든 업체 order_url 로, 업체 secret 으로 서명
 * - 주문당 1회 (webhook_sent_at 원자적 선점 → 버튼 재클릭/동시요청에도 중복전송 없음)
 * - 재전송 없음: 실패는 webhook_deliveries 에 남기고 주문은 막지 않는다. 누락분은 EIS 가 pull API 로 보정.
 */
class OrderWebhookSender
{
    public function sendOnce(Order $order): void
    {
        $claimed = Order::whereKey($order->id)->whereNull('webhook_sent_at')->update(['webhook_sent_at' => now()]);
        if ($claimed === 0) {
            return;
        }

        $order->loadMissing(['items', 'store']);
        $hook = self::integration($order->store->vendor_id);
        if (! $hook || ! $hook->is_active || $hook->order_url === '') {
            $this->log($order, null, false, 'FAIL (no config)', null);

            return;
        }

        $payload = ['orders' => self::payloadRows($order, $hook->eis_code)];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        try {
            $res = Http::timeout(config('siren.webhook_timeout'))
                ->withHeaders(['X-Dadamda-Event' => 'order.completed', 'X-Dadamda-Signature' => 'sha256='.hash_hmac('sha256', $body, $hook->secret)])
                ->withBody($body, 'application/json')
                ->post($hook->order_url);
            $this->log($order, $hook->order_url, $res->successful(), ($res->successful() ? 'OK' : 'FAIL')." (HTTP {$res->status()})", $payload);
        } catch (ConnectionException) {
            $this->log($order, $hook->order_url, false, 'FAIL (timeout/connection refused)', $payload);
        }
    }

    public static function integration(?int $vendorId): ?object
    {
        return $vendorId ? DB::table('vendor_integrations')->where('vendor_id', $vendorId)->first() : null;
    }

    /** 원본과 같은 평탄 형식 (상품 1개 = 1행). seller_id = 업체 EIS 코드, store_code = 매장 구분용 추가 필드 */
    public static function payloadRows(Order $order, ?string $sellerId = null): array
    {
        $order->loadMissing(['items', 'store']);
        $sellerId ??= self::integration($order->store->vendor_id)?->eis_code ?? $order->store->code;

        return $order->items->map(fn ($i) => [
            'event' => 'order.completed',
            'order_no' => $order->order_no,
            'ordered_at' => $order->created_at->format('Y-m-d H:i:s'),
            'seller_id' => $sellerId,
            'store_code' => $order->store->code,
            'product_number' => (string) $i->product_id,
            'product_name' => $i->name,
            'qty' => $i->qty,
            'options' => (string) $i->option_name,
            'unit_price' => $i->unit_price,
            'add_price' => 0,
            'line_total' => $i->line_total,
            'total_amount' => $order->total_amount,
            'pay_method' => self::payMethodLabel((string) $order->pay_method, (string) $i->name),
            'card_approval_no' => $order->card_approval_no,
        ])->values()->all();
    }

    /**
     * EIS 상품행 결제수단: '카드' / '현금' / '이체' / '' (원본 inc/webhook_order.php _wh_pay_method, 2026-11-20).
     *  - 이관 앱 코드: card·pos_card → 카드, pos_cash → 현금, pos_transfer → 이체
     *  - 원본에서 옮겨 온 결제수단 글자(마일리지결제·네이버페이 등)는 원본 규칙 그대로:
     *    '마일리지' → 상품명 앞 [현금]→현금, [계좌이체]→이체, 그 외 카드 (현장카드 개발 전 임시 구분)
     *    '현금' → 현금, '계좌이체'·'무통장' → 이체, 그 외(간편결제·온라인 PG) → 카드, 비면 ''
     */
    public static function payMethodLabel(string $payMethod, string $itemName = ''): string
    {
        $t = trim($payMethod);

        return match (true) {
            $t === '' => '',
            in_array($t, ['card', 'pos_card'], true) => '카드',
            $t === 'pos_cash' => '현금',
            $t === 'pos_transfer' => '이체',
            str_contains($t, '마일리지') => str_starts_with($itemName, '[현금]') ? '현금' : (str_starts_with($itemName, '[계좌이체]') ? '이체' : '카드'),
            str_contains($t, '현금') => '현금',
            str_contains($t, '계좌이체') || str_contains($t, '무통장') => '이체',
            default => '카드',
        };
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
