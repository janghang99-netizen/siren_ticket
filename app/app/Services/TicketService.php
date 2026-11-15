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
    public function purchase(Event $event, SeatSection $section, int $qty, string $name, string $phone): Collection
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

        return DB::transaction(function () use ($event, $section, $qty, $name, $phone) {
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
            ]));
        });
    }

    /** 슈퍼어드민 티켓 취소: 좌석을 구역 잔여석으로 돌려준다. 입장한 티켓은 취소 불가. */
    public function cancel(Ticket $ticket): Ticket
    {
        return DB::transaction(function () use ($ticket) {
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($ticket->status !== 'paid') {
                throw new OrderException($ticket->status === 'used' ? '입장한 티켓은 취소할 수 없어요.' : '이미 취소된 티켓이에요.', 'bad_transition', 409);
            }
            $ticket->update(['status' => 'cancelled']);
            SeatSection::whereKey($ticket->seat_section_id)->where('sold', '>', 0)->decrement('sold');

            return $ticket;
        });
    }
}
