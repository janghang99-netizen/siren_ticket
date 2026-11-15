<?php

namespace App\Services;

use App\Models\Event;
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
    public function purchase(Event $event, SeatSection $section, int $qty, string $name, string $phone, ?\Carbon\CarbonInterface $termsAgreedAt = null): Collection
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

        return DB::transaction(function () use ($event, $section, $qty, $name, $phone, $termsAgreedAt) {
            $owned = Ticket::where('event_id', $event->id)->where('holder_phone', $phone)
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

            return collect(range(0, $qty - 1))->map(fn ($i) => Ticket::create([
                'ticket_no' => strtoupper($section->code.'-'.Str::random(8)),
                'event_id' => $event->id,
                'seat_section_id' => $section->id,
                'row_no' => intdiv($first + $i, self::SEATS_PER_ROW) + 1,
                'seat_no' => (($first + $i) % self::SEATS_PER_ROW) + 1,
                'holder_name' => $name,
                'holder_phone' => $phone,
                'price' => $section->price,
                'status' => 'paid',
                'paid_at' => now(),
                'terms_agreed_at' => $termsAgreedAt,
            ]));
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

    private function doCancel(Ticket $ticket, string $by): Ticket
    {
        return DB::transaction(function () use ($ticket, $by) {
            $ticket = Ticket::with('event')->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($ticket->status !== 'paid') {
                throw new OrderException($ticket->status === 'used' ? '입장한 티켓은 취소할 수 없어요.' : '이미 취소된 티켓이에요.', 'bad_transition', 409);
            }

            $rate = $by === 'admin' ? 0 : $ticket->cancelFeeRate();
            if ($rate === null) {
                throw new OrderException('공연 당일에는 예매를 취소할 수 없어요.', 'cancel_closed', 409);
            }
            $fee = (int) (floor($ticket->price * $rate / 100 / 10) * 10);   // 10원 단위 절사

            $ticket->update([
                'status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $by,
                'cancel_fee' => $fee, 'refund_amount' => $ticket->price - $fee,
            ]);
            SeatSection::whereKey($ticket->seat_section_id)->where('sold', '>', 0)->decrement('sold');

            return $ticket;
        });
    }
}
