<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\CapacitySlot;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SettlementAdjustment;
use App\Models\Store;
use App\Models\Customer;
use App\Models\Ticket;
use App\Services\Benefits\BenefitService;
use App\Services\Payments\PaymentGateway;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * 입점 매장 사이렌오더 주문 라이프사이클.
 * 원본 대응: my_pay_market.php(생성) / pay_market_ok()(확정) / ajax_seller_order_update.php(상태변경·취소).
 * 정책: docs/OPENMARKET_POLICY.md (B1 판매중지, B2 주류, D1 구매자 취소, C6 정산 후 환불 공제, E1 약관 동의)
 */
class OrderService
{
    public function __construct(
        private OrderWebhookSender $webhook,
        private InventoryService $inventory,
        private BenefitService $benefits,
    ) {}

    /**
     * 주문 생성 (미결제). 가격은 서버에서 다시 계산하고, 캐파·재고는 원자적으로 차감한다.
     *
     * @param  array<int, array{product_id:int, option_id?:int|null, qty:int, dine_option?:string}>  $items
     * @param  bool  $adultConfirmed  주류가 담긴 온라인 주문은 성인 확인 필수 (입점계약 제6조 주류 특칙)
     * @param  Customer|null  $customer  휴대폰 번호 고객 — 적립·스탬프 대상. 쿠폰·마일리지를 쓰려면 인증된 고객이어야 한다(컨트롤러 확인).
     */
    public function place(Store $store, array $items, string $payMethod, ?int $slotId = null,
        string $channel = 'online', ?Ticket $ticket = null, ?string $phone = null,
        bool $adultConfirmed = false, ?CarbonInterface $termsAgreedAt = null,
        ?Customer $customer = null, ?int $couponIssueId = null, int $mileageUse = 0): Order
    {
        if ($channel === 'pos' && ($couponIssueId || $mileageUse > 0)) {
            throw new OrderException('현장판매에서는 쿠폰·마일리지를 쓸 수 없어요.');
        }
        if (! $store->is_open || ($store->vendor_id && ! $store->vendor?->isActive())) {
            throw new OrderException("{$store->name}은(는) 지금 주문을 받지 않아요.", 'store_closed');
        }
        // 묶음결제는 같은 매장 상품끼리만 — 다른 매장 상품은 따로 주문·결제 (매장별 정산·픽업이 다르다)
        $ids = collect($items)->pluck('product_id')->filter()->map(fn ($v) => (int) $v)->unique();
        if ($ids->isNotEmpty() && Product::whereIn('id', $ids)->where('store_id', '!=', $store->id)->exists()) {
            throw new OrderException('묶음결제는 같은 매장 상품끼리만 할 수 있어요. 다른 매장 상품은 따로 주문해 주세요.', 'mixed_store');
        }
        if (! array_key_exists($payMethod, config('siren.gateways'))) {
            throw new OrderException('지원하지 않는 결제수단입니다.');
        }
        $isPosMethod = in_array($payMethod, config('siren.pos_methods'), true);
        if ($isPosMethod !== ($channel === 'pos')) {
            throw new OrderException('이 결제수단은 여기서 쓸 수 없습니다.');
        }

        return DB::transaction(function () use ($store, $items, $payMethod, $slotId, $channel, $ticket, $phone, $adultConfirmed, $termsAgreedAt, $customer, $couponIssueId, $mileageUse) {
            $slot = $slotId ? $this->claimCapacity($store, $slotId) : null;

            $lines = [];
            $total = 0;
            $earn = 0;
            $unitPrices = [];
            $earnCap = (float) Setting::get('mileage_product_max_rate');
            foreach ($items as $it) {
                $qty = (int) ($it['qty'] ?? 0);
                if ($qty < 1) {
                    continue;
                }
                $product = Product::sellable()->where('store_id', $store->id)->find($it['product_id'] ?? 0)
                    ?? throw new OrderException('지금 판매하지 않는 메뉴가 담겨 있어요.');
                if ($product->sold_out_at) {
                    throw new OrderException("‘{$product->name}’은(는) 방금 품절됐어요. 장바구니에서 빼고 다시 주문해 주세요.", 'sold_out');
                }
                $option = empty($it['option_id']) ? null
                    : ($product->options()->find($it['option_id']) ?? throw new OrderException('없는 옵션이 담겨 있어요.'));

                $unit = $product->price + ($option?->extra_price ?? 0);
                $lines[] = [$product, $option, $qty, $unit, ($it['dine_option'] ?? '포장') === '매장' ? '매장' : '포장'];
                $total += $unit * $qty;
                // 업체가 상품에 건 적립금 (판매가의 상한 % 이내) — 원본 auction_product.point × 수량
                $earn += min($product->mileage_points, (int) floor($product->price * $earnCap / 100)) * $qty;
                $unitPrices[$product->id] = $product->price;
            }
            if ($lines === []) {
                throw new OrderException('담긴 메뉴가 없어요.');
            }

            $hasAlcohol = collect($lines)->contains(fn ($l) => $l[0]->is_alcohol);
            // 주류판매 자격이 승인되지 않은(반려된) 업체 매장의 주류는 받지 않는다
            if ($hasAlcohol && ! $store->vendor?->canSellLiquor()) {
                throw new OrderException('이 매장은 지금 주류를 팔 수 없어요. 주류를 빼고 주문해 주세요.', 'liquor_not_allowed');
            }
            if ($hasAlcohol && $channel === 'online' && ! $adultConfirmed) {
                throw new OrderException('주류는 만 19세 이상만 주문할 수 있어요. 성인 확인에 동의해 주세요.', 'adult_required');
            }

            $code = $this->pickupCode($store);
            $order = Order::create([
                'order_no' => $store->code.'-'.now()->format('YmdHis').'-'.$code,
                'pickup_code' => $code,
                'store_id' => $store->id,
                'capacity_slot_id' => $slot?->id,
                'ticket_id' => $ticket?->id,
                'status' => OrderStatus::Pending,
                'channel' => $channel,
                'requires_id_check' => $hasAlcohol,   // 수령 시 실물 신분증 확인 (매장 책임)
                'terms_agreed_at' => $termsAgreedAt,
                'adult_confirmed_at' => $hasAlcohol && $adultConfirmed ? now() : null,
                'pay_method' => $payMethod,
                'buyer_phone' => $phone ?? $customer?->phone ?? $ticket?->holder_phone,
                'customer_id' => $customer?->id,
                'total_amount' => $total,
                'paid_amount' => $total,
                'mileage_earn' => $customer ? $earn : 0,
                'commission_rate' => $store->commission_rate,
            ]);

            foreach ($lines as [$product, $option, $qty, $unit, $dine]) {
                $deducted = $product->stock_deduct_at === 'order'
                    && $this->inventory->sell($product, $option, $qty, true, $order);
                $order->items()->create([
                    'product_id' => $product->id,
                    'product_option_id' => $option?->id,
                    'name' => $product->name,
                    'option_name' => $option?->name,
                    'unit_price' => $unit,
                    'qty' => $qty,
                    'line_total' => $unit * $qty,
                    'dine_option' => $dine,
                    'stock_deducted' => $deducted,
                ]);
            }

            if ($customer && ($couponIssueId || $mileageUse > 0)) {
                $this->benefits->applyToOrder($order, $customer, $store, $couponIssueId, $mileageUse, $unitPrices);
            }

            return $order->load('items');
        });
    }

    /**
     * 결제 확정 — 유일한 확정 경로 (원본 pay_market_ok). 멱등: 이미 확정된 주문은 그대로 반환.
     * 원본은 루프 안에서 $result 를 덮어써 첫 상품만 재고차감되던 버그가 있었다 → 라인별로 처리.
     */
    public function confirmPayment(Order $order, ?string $pgTid = null): Order
    {
        return DB::transaction(function () use ($order, $pgTid) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== OrderStatus::Pending) {
                return $order->load('items');
            }

            foreach ($order->items()->with(['product', 'option'])->get() as $item) {
                if (! $item->stock_deducted && $item->product->stock_deduct_at === 'payment') {
                    // 이미 돈을 받은 뒤라 막지 않는다 (원본과 동일: 음수 재고 허용 → 매장이 품절 처리)
                    $item->update(['stock_deducted' => $this->inventory->sell($item->product, $item->option, $item->qty, false, $order)]);
                }
            }

            $order->update(['status' => OrderStatus::Paid, 'paid_at' => now(), 'pg_tid' => $pgTid]);

            DB::table('store_notifications')->insert([
                'store_id' => $order->store_id, 'order_id' => $order->id, 'type' => 'new_order',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $order->load('items');
        });
    }

    /** 현장판매(POS): 생성 + 즉시 확정 (원본 ajax_seller_pos_order.php 와 같은 순서) */
    public function placePos(Store $store, array $items, string $payMethod, ?Customer $customer = null): Order
    {
        // 현장판매도 휴대폰 번호를 받으면 스탬프·적립 대상 (원본 ajax_seller_pos_order.php stamp_phone)
        return $this->confirmPayment($this->place($store, $items, $payMethod, channel: 'pos', customer: $customer));
    }

    /** 매장의 상태 변경. 취소는 결제수단별 환불 → 성공 시에만 환불완료(14)로. */
    public function transition(Order $order, OrderStatus $to): Order
    {
        if ($to === OrderStatus::Cancelled) {
            return $this->cancel($order, $order->channel === 'pos' ? '현장판매 취소' : '매장 취소');
        }

        // 한 칸 뒤로 = 되돌리기 (실수로 누른 경우)
        if ($order->status->previous() === $to) {
            return $this->revert($order);
        }

        $order = DB::transaction(function () use ($order, $to) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $from = $order->status;

            if (! $from->canTransitionTo($to)) {
                throw new OrderException("{$from->label()} 주문은 {$to->label()}(으)로 바꿀 수 없어요.", 'bad_transition', 409);
            }

            $column = match ($to) {
                OrderStatus::Preparing => 'preparing_at',
                OrderStatus::Ready => 'ready_at',
                OrderStatus::PickedUp => 'picked_up_at',
            };
            $order->update(['status' => $to, $column => now()]);
            if ($to === OrderStatus::PickedUp) {
                $this->benefits->earnForOrder($order);   // 원본: 수령완료(5) 때 스탬프·적립
            }

            return $order;
        });

        if ($order->status === OrderStatus::PickedUp) {
            $this->webhook->sendOnce($order);   // 커밋 후 전송. 실패해도 주문은 그대로 (원본 정책)
        }
        if ($order->status === OrderStatus::Ready) {
            $this->notifyReady($order);
        }

        return $order->load('items');
    }

    /**
     * 매장 보드 되돌리기: 만드는 중 → 새 주문, 픽업 대기 → 만드는 중, 건네줬어요 → 픽업 대기.
     *  - 건네줬어요(수령완료)는 정산에 들어가기 전, 보드에 남아 있는 동안(board_picked_up_minutes)만 되돌린다.
     *  - 적립·스탬프·업체 웹훅은 한 번만 처리되게 되어 있어(idem key·webhook_sent_at) 그대로 둔다 — 다시 건네도 두 번 쌓이지 않는다.
     */
    public function revert(Order $order): Order
    {
        $order = DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $from = $order->status;
            $to = $from->previous();

            if (! $to) {
                throw new OrderException("{$from->label()} 주문은 되돌릴 수 없어요.", 'bad_transition', 409);
            }
            if ($from === OrderStatus::PickedUp) {
                $window = (int) config('siren.board_picked_up_minutes');
                if ($order->settlement_id || ! $order->picked_up_at || $order->picked_up_at->lt(now()->subMinutes($window))) {
                    throw new OrderException("건넨 지 {$window}분이 지났거나 정산에 들어간 주문은 되돌릴 수 없어요.", 'revert_expired', 409);
                }
            }

            $column = match ($from) {
                OrderStatus::Preparing => 'preparing_at',
                OrderStatus::Ready => 'ready_at',
                OrderStatus::PickedUp => 'picked_up_at',
            };
            $order->update(['status' => $to, $column => null]);

            return $order;
        });

        \App\Services\Audit::log('order.reverted', $order, "주문 {$order->pickup_code} 되돌림 → {$order->status->label()}");

        return $order->load('items');
    }

    /**
     * 손님에게 "준비 완료" 문자 — 주문 화면을 닫아 둔 손님도 알 수 있게. 한 주문에 한 번만
     * (되돌렸다가 다시 준비 완료가 돼도 또 보내지 않는다). 번호를 모르는 주문(현장판매 등)은 건너뛴다.
     * 실패해도 주문 처리는 막지 않는다.
     */
    public function notifyReady(Order $order): void
    {
        if (! config('siren.notify_ready_sms') || ! $order->customer_id) {
            return;
        }
        if (! \Illuminate\Support\Facades\Cache::add("order:ready_sms:{$order->id}", 1, now()->addDay())) {
            return;
        }
        try {
            $phone = \App\Models\Customer::find($order->customer_id)?->smsPhone();
            if ($phone) {
                $store = $order->store()->value('name');
                app(\App\Services\Benefits\SmsSender::class)->send($phone,
                    '['.Setting::brand()."] 주문 {$order->pickup_code} 준비됐어요. {$store}에서 번호를 보여 주고 받아 가세요.");
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** D1 구매자 청약철회: 매장이 준비를 시작하기 전(결제완료)이면 바로 취소·환불 */
    public function cancelByBuyer(Order $order): Order
    {
        return $this->cancel($order, '구매자 취소');
    }

    /**
     * 운영사 환불 (수령완료·정산완료 주문 포함). 상품이 이미 전달됐으므로 재고는 되돌리지 않는다.
     * 정산완료(6) 주문이면 업체에 이미 나간 정산금을 차기 정산에서 공제한다 (입점계약 제7·8조).
     */
    public function adminRefund(Order $order, string $reason): Order
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = Order::with('store')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, [OrderStatus::PickedUp, OrderStatus::Settled], true)) {
                throw new OrderException('수령완료·정산완료 주문만 여기서 환불해요. 진행 중 주문은 매장 취소를 쓰세요.', 'bad_transition', 409);
            }
            $this->refundOrFail($order);
            $this->benefits->revokeForOrder($order);   // 쓴 마일리지 반환, 받은 적립금·스탬프 회수

            if ($order->status === OrderStatus::Settled && $order->store->vendor_id) {
                SettlementAdjustment::create([
                    'vendor_id' => $order->store->vendor_id,
                    'order_id' => $order->id,
                    'amount' => $order->vendorNet(),   // 그 주문으로 업체가 받은 정산금
                    'reason' => "정산 후 환불 ({$order->pickup_code}) · {$reason}",
                ]);
            }

            $order->update([
                'status' => OrderStatus::Refunded, 'cancelled_at' => now(), 'refunded_at' => now(),
                'cancel_reason' => '운영사 환불: '.$reason,
            ]);

            return $order;
        });
    }

    /** D2 구매자 취소 요청: 매장이 준비를 시작한 뒤(준비중·준비완료)에는 바로 취소하지 않고 매장에 요청한다. */
    public function requestCancel(Order $order, string $reason): Order
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status === OrderStatus::Paid) {
                throw new OrderException('아직 준비 전이라 요청 없이 바로 취소할 수 있어요.', 'bad_transition', 409);
            }
            if (! in_array($order->status, [OrderStatus::Preparing, OrderStatus::Ready], true)) {
                throw new OrderException('이미 받은 주문이나 취소된 주문은 취소를 요청할 수 없어요.', 'bad_transition', 409);
            }
            if ($order->cancel_requested_at) {
                throw new OrderException($order->cancel_request_result === 'rejected' ? '매장이 이미 이 요청을 거절했어요. 매장에 직접 문의해 주세요.' : '이미 취소를 요청했어요. 매장 답변을 기다려 주세요.', 'duplicate', 409);
            }
            $order->update(['cancel_requested_at' => now(), 'cancel_request_reason' => $reason]);

            return $order;
        });
    }

    /** D2 매장 응답: 승인하면 환불(재고·캐파 반환), 거절은 사유 필수 (정당한 사유 없이 거부 불가 — 입점계약 제8조) */
    public function respondCancel(Order $order, bool $approve, ?string $response): Order
    {
        if (! $approve && blank($response)) {
            throw new OrderException('거절할 때는 사유를 적어야 해요. 구매자에게 그대로 보여요.', 'reason_required');
        }

        return DB::transaction(function () use ($order, $approve, $response) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $order->cancel_requested_at || $order->cancel_responded_at) {
                throw new OrderException('답변할 취소 요청이 없어요.', 'bad_transition', 409);
            }

            $fields = ['cancel_request_result' => $approve ? 'approved' : 'rejected', 'cancel_request_response' => $response, 'cancel_responded_at' => now()];
            if ($approve) {
                if (! in_array($order->status, [OrderStatus::Preparing, OrderStatus::Ready], true)) {
                    throw new OrderException('이미 건넨 주문이라 여기서 취소할 수 없어요. 운영사에 환불을 요청해 주세요.', 'bad_transition', 409);
                }
                $this->refundOrFail($order);
                $this->releaseResources($order);
                $this->benefits->releaseOrder($order);
                $this->benefits->revokeEarned($order);
                $fields += ['status' => OrderStatus::Refunded,'cancelled_at' => now(), 'refunded_at' => now(), 'cancel_reason' => '구매자 요청 취소(매장 승인)'];
            }
            $order->update($fields);

            return $order;
        });
    }

    /** F4 공연 취소: 그 공연 시간대에 받은 매장 주문을 상태와 관계없이(수령 전) 환불·종료. @return bool 처리했으면 true */
    public function cancelForEvent(Order $order, string $reason): bool
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status === OrderStatus::Pending) {
                $this->releaseResources($order);
                $this->benefits->releaseOrder($order);
                $order->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => $reason]);

                return true;
            }
            if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::Preparing, OrderStatus::Ready], true)) {
                return false;
            }
            $this->refundOrFail($order);
            $this->releaseResources($order);
            $this->benefits->releaseOrder($order);
            $this->benefits->revokeEarned($order);
            $order->update(['status' => OrderStatus::Refunded, 'cancelled_at' => now(), 'refunded_at' => now(), 'cancel_reason' => $reason]);

            return true;
        });
    }

    /** 결제 안 된 채 방치된 주문을 취소해 캐파·재고를 돌려준다. @return int 만료 건수 */
    public function expirePending(): int
    {
        $cutoff = now()->subMinutes(config('siren.pending_ttl_minutes'));
        $count = 0;
        foreach (Order::where('status', OrderStatus::Pending)->where('created_at', '<', $cutoff)->pluck('id') as $id) {
            $count += DB::transaction(function () use ($id) {
                $order = Order::whereKey($id)->lockForUpdate()->first();
                if ($order?->status !== OrderStatus::Pending) {
                    return 0;
                }
                $this->releaseResources($order);
                $this->benefits->releaseOrder($order);
                $order->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => '결제 시간 초과']);

                return 1;
            });
        }

        return $count;
    }

    /** 결제완료 상태에서만 취소 → 결제수단별 환불 → 재고·캐파 반환 → 환불완료(14) */
    private function cancel(Order $order, string $reason): Order
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $order->status->canTransitionTo(OrderStatus::Cancelled)) {
                throw new OrderException('매장이 이미 준비를 시작해서 취소할 수 없어요. 매장에 직접 문의해 주세요.', 'bad_transition', 409);
            }
            $this->refundOrFail($order);
            $this->releaseResources($order);
            $this->benefits->releaseOrder($order);
            // 수령완료를 되돌린 뒤 취소하는 경우: 수령 때 준 적립금·스탬프를 회수한다 (적립만 남기지 못하게)
            $this->benefits->revokeEarned($order);
            $order->update([
                'status' => OrderStatus::Refunded, 'cancelled_at' => now(), 'refunded_at' => now(),
                'cancel_reason' => $reason,
            ]);

            return $order->load('items');
        });
    }

    private function refundOrFail(Order $order): void
    {
        /** @var PaymentGateway $gateway */
        $gateway = app(config('siren.gateways')[$order->pay_method]);
        if (! $gateway->refund($order)) {
            throw new OrderException('환불에 실패해 주문을 그대로 두었습니다. 결제사 상태를 확인해 주세요.', 'refund_failed', 502);
        }
    }

    private function claimCapacity(Store $store, int $slotId): CapacitySlot
    {
        $slot = CapacitySlot::where('store_id', $store->id)->find($slotId)
            ?? throw new OrderException('주문 시간대를 다시 골라 주세요.');

        $claimed = CapacitySlot::whereKey($slot->id)
            ->where('is_closed', false)
            ->whereColumn('used', '<', 'capacity')
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', now()))
            ->increment('used');

        if ($claimed === 0) {
            throw new OrderException('이 시간대 주문이 마감됐어요. 다음 시간대에 다시 주문해 주세요.', 'capacity_closed');
        }

        return $slot;
    }

    private function releaseResources(Order $order): void
    {
        foreach ($order->items()->with(['product', 'option'])->get() as $item) {
            if ($item->stock_deducted) {
                $this->inventory->restore($item->product, $item->option, $item->qty, $order);
                $item->update(['stock_deducted' => false]);
            }
        }
        if ($order->capacity_slot_id) {
            CapacitySlot::whereKey($order->capacity_slot_id)->where('used', '>', 0)->decrement('used');
        }
    }

    /** 같은 매장의 진행 중 주문끼리 겹치지 않는 4자리 픽업번호 */
    private function pickupCode(Store $store): string
    {
        for ($i = 0; $i < 30; $i++) {
            $code = (string) random_int(1000, 9999);
            $taken = Order::where('store_id', $store->id)->where('pickup_code', $code)
                ->whereIn('status', [1, 2, 3, 4])->exists();
            if (! $taken) {
                return $code;
            }
        }

        throw new OrderException('잠시 후 다시 주문해 주세요.', 'busy', 503);
    }
}
