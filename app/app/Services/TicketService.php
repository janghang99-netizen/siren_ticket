<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Event;
use App\Services\Benefits\BenefitService;
use App\Models\SeatSection;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketService
{
    /** 한 열의 좌석 수 (자동 좌석 배정용) */
    private const SEATS_PER_ROW = 20;

    /**
     * 티켓 구매. 좌석 차감은 원자적 UPDATE 하나로 — 오픈 직후 동시 접속에서도 초과판매 없음.
     * (차감한 행의 잠금은 커밋까지 유지되므로, 직후에 읽는 sold 값으로 좌석번호를 안전하게 배정한다.)
     *
     * @return Collection<int, Ticket>
     */
    public function purchase(Event $event, SeatSection $section, int $qty, string $name, string $phone, ?\Carbon\CarbonInterface $termsAgreedAt = null,
        ?int $couponIssueId = null, int $mileageUse = 0, ?\App\Models\Member $member = null): Collection
    {
        $phone = preg_replace('/\D/', '', $phone);

        if ($section->event_id !== $event->id) {
            throw new OrderException('이 공연의 구역이 아닙니다.');
        }
        if (! $event->salesOpen()) {
            throw new OrderException('지금은 예매 기간이 아닙니다.', 'sales_closed');
        }
        if ($qty < 1 || $qty > $event->max_per_buyer) {
            throw new OrderException("한 번에 1~{$event->max_per_buyer}매까지 예매할 수 있어요.");
        }

        return DB::transaction(function () use ($event, $section, $qty, $name, $phone, $termsAgreedAt, $couponIssueId, $mileageUse, $member) {
            // 1인 매수 제한: 같은 번호 또는 같은 회원 (번호를 바꿔 가며 사는 리셀 방지)
            $owned = Ticket::where('event_id', $event->id)
                ->where(fn ($q) => $q->where('holder_phone', $phone)->when($member, fn ($q) => $q->orWhere('member_id', $member->id)))
                ->where('status', '!=', 'cancelled')->lockForUpdate()->count();
            if ($owned + $qty > $event->max_per_buyer) {
                $left = max(0, $event->max_per_buyer - $owned);
                throw new OrderException("1인당 {$event->max_per_buyer}매까지예요. 이 번호로 {$left}매 더 예매할 수 있어요.", 'buyer_limit');
            }

            $claimed = SeatSection::whereKey($section->id)
                ->whereRaw('sold + ? <= capacity', [$qty])
                ->increment('sold', $qty);
            if ($claimed === 0) {
                $left = $section->fresh()->remaining();
                throw new OrderException($left > 0
                    ? "{$section->code}구역은 {$left}석만 남았어요. 수량을 줄여 주세요."
                    : "{$section->code}구역이 매진됐어요. 다른 구역을 골라 주세요.", 'sold_out');
            }

            // 좌석 번호는 이 구역에 발급된 티켓 수(취소분 포함) 다음부터 — 취소된 좌석 번호를 재사용하지 않아 중복이 없다.
            // 위 increment 가 구역 행을 잠갔으므로 같은 구역 동시 구매는 여기서 직렬화된다.
            $first = Ticket::where('seat_section_id', $section->id)->count();
            $customer = $member?->customer ?? Customer::forPhone($phone);   // 관람 후 마일리지·스탬프 대상 (PG user_id = customer_key)
            $bookingNo = 'B'.now()->format('ymd').strtoupper(Str::random(8));

            $tickets = collect(range(0, $qty - 1))->map(fn ($i) => Ticket::create([
                'ticket_no' => strtoupper($section->code.'-'.Str::random(8)),
                'booking_no' => $bookingNo,
                'event_id' => $event->id,
                'seat_section_id' => $section->id,
                'customer_id' => $customer->id,
                'member_id' => $member?->id,
                'row_no' => intdiv($first + $i, self::SEATS_PER_ROW) + 1,
                'seat_no' => (($first + $i) % self::SEATS_PER_ROW) + 1,
                'holder_name' => $name,
                'holder_phone' => $phone,
                'price' => $section->price,
                'paid_amount' => $section->price,
                'status' => 'paid',
                'paid_at' => now(),
                'terms_agreed_at' => $termsAgreedAt,
            ]));

            if ($couponIssueId || $mileageUse > 0) {
                app(BenefitService::class)->applyToBooking($tickets, $customer, $event, $bookingNo, $couponIssueId, $mileageUse);
            }

            return $tickets->map->fresh();
        });
    }

    /** 운영사 티켓 취소: 전액 환불, 좌석은 다시 판매로. 입장한 티켓은 취소 불가. */
    public function cancel(Ticket $ticket): Ticket
    {
        return $this->doCancel($ticket, 'admin');
    }

    /**
     * F3 구매자 취소: 취소 수수료 규정 적용 (관람일 10일 전까지 0%, 9~7일 10%, 6~3일 20%, 2~1일 30%, 당일 불가).
     * 수수료를 뺀 금액을 환불한다.
     */
    public function cancelByBuyer(Ticket $ticket): Ticket
    {
        return $this->doCancel($ticket, 'buyer');
    }

    /**
     * F4 공연 취소: 공연을 취소 상태로 바꾸고, 판매된 티켓을 전액 환불, 매장 주문 시간대를 닫고
     * 그 공연 시간대에 받은 매장 주문(수령 전)을 환불한다.
     *
     * @return array{tickets:int, refund:int, orders:int}
     */
    public function cancelEvent(\App\Models\Event $event, string $reason, OrderService $orders): array
    {
        if ($event->isCancelled()) {
            throw new OrderException('이미 취소된 공연이에요.', 'bad_transition', 409);
        }
        $event->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason]);
        $event->capacitySlots()->update(['is_closed' => true]);

        $tickets = 0;
        $refund = 0;
        foreach (Ticket::where('event_id', $event->id)->where('status', 'paid')->pluck('id') as $id) {
            $t = $this->doCancel(Ticket::find($id), 'admin');
            $tickets++;
            $refund += $t->refund_amount;
        }

        $count = 0;
        $slotIds = $event->capacitySlots()->pluck('id');
        foreach (\App\Models\Order::whereIn('capacity_slot_id', $slotIds)->whereIn('status', [1, 2, 3, 4])->pluck('id') as $id) {
            $count += (int) $orders->cancelForEvent(\App\Models\Order::find($id), "공연 취소: {$reason}");
        }

        return ['tickets' => $tickets, 'refund' => $refund, 'orders' => $count];
    }

    private function doCancel(Ticket $ticket, string $by): Ticket
    {
        return DB::transaction(function () use ($ticket, $by) {
            $ticket = Ticket::with('event')->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($ticket->status !== 'paid') {
                throw new OrderException($ticket->status === 'used' ? '입장한 티켓은 취소할 수 없어요.' : '이미 취소된 티켓이에요.', 'bad_transition', 409);
            }

            // 운영사 취소·공연 취소는 전액, 공연이 취소됐으면 구매자 취소도 전액
            $rate = ($by === 'admin' || $ticket->event->isCancelled()) ? 0 : $ticket->cancelFeeRate();
            if ($rate === null) {
                throw new OrderException('공연 당일에는 예매를 취소할 수 없어요.', 'cancel_closed', 409);
            }
            $fee = (int) (floor($ticket->price * $rate / 100 / 10) * 10);   // 10원 단위 절사, 정가 기준

            $ticket->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $by, 'cancel_fee' => $fee]);
            // 현금은 결제한 만큼에서 수수료를 빼고 돌려주고, 쓴 마일리지·쿠폰은 혜택 원장으로 되돌린다
            $ticket->update(['refund_amount' => app(BenefitService::class)->releaseTicket($ticket, $fee)]);
            SeatSection::whereKey($ticket->seat_section_id)->where('sold', '>', 0)->decrement('sold');

            return $ticket;
        });
    }
}
