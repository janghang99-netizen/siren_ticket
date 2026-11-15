<?php

namespace App\Services\Nft;

use App\Models\Customer;
use App\Models\CustomerWallet;
use App\Models\Ticket;
use App\Models\TicketToken;
use App\Services\OrderException;
use Illuminate\Support\Facades\DB;

/**
 * NFT 티켓: 회원 예매 → 발행, 취소 → 소각, 입장 → 사용 처리.
 * 입장 코드는 30초마다 바뀌는 서명 값이라 스크린샷·캡처 공유로는 들어갈 수 없고, 한 번 검표하면 다시 못 쓴다.
 * 메타데이터에는 공연·좌석만 넣고 이름·번호 같은 개인정보는 넣지 않는다 (체인은 지울 수 없다).
 */
class NftTicketService
{
    private const ENTRY_WINDOW = 30;

    public function __construct(private TicketChain $chain) {}

    public function walletFor(Customer $customer): CustomerWallet
    {
        return CustomerWallet::firstOrCreate(['customer_id' => $customer->id], [
            'network' => $this->chain->network(), 'address' => $this->chain->createWallet($customer->id),
        ]);
    }

    /** 회원 티켓만 발행 (비회원 시절 티켓·관리자 발급분은 토큰 없음) */
    public function mint(Ticket $ticket): ?TicketToken
    {
        if (! $ticket->member_id || ! $ticket->customer_id || $ticket->status !== 'paid' || $ticket->token) {
            return $ticket->token;
        }
        $wallet = $this->walletFor($ticket->customer);
        $meta = $this->metadata($ticket);
        $hash = hash('sha256', json_encode($meta, JSON_UNESCAPED_UNICODE));
        $tokenId = (string) $ticket->id;
        $tx = $this->chain->mint($tokenId, $wallet->address, route('nft.metadata', $tokenId), $hash);

        return TicketToken::create([
            'ticket_id' => $ticket->id, 'network' => $this->chain->network(), 'contract' => $this->chain->contract(),
            'token_id' => $tokenId, 'owner_address' => $wallet->address, 'status' => 'minted', 'transferable' => false,
            'metadata_hash' => $hash, 'mint_tx' => $tx, 'minted_at' => now(),
        ]);
    }

    public function burn(Ticket $ticket): void
    {
        $token = TicketToken::where('ticket_id', $ticket->id)->where('status', 'minted')->first();
        if ($token) {
            $token->update(['status' => 'burned', 'burn_tx' => $this->chain->burn($token->token_id), 'burned_at' => now()]);
        }
    }

    /** ERC-721 메타데이터 (공개) — 개인정보 없음 */
    public function metadata(Ticket $ticket): array
    {
        $ticket->loadMissing('event', 'section');
        $e = $ticket->event;

        return [
            'name' => "{$e->artist} · {$ticket->seatLabel()}",
            'description' => "{$e->name} — {$e->venue} ".$e->starts_at->format('Y.m.d H:i').'. SIREN 공식 좌석 티켓 (양도 불가).',
            'image' => route('nft.image', $ticket->id),
            'external_url' => route('concerts.show', $e),
            'attributes' => [
                ['trait_type' => 'Artist', 'value' => $e->artist],
                ['trait_type' => 'Event', 'value' => $e->name],
                ['trait_type' => 'Date', 'value' => $e->starts_at->format('Y-m-d')],
                ['trait_type' => 'Venue', 'value' => (string) $e->venue],
                ['trait_type' => 'Tier', 'value' => $ticket->section->tier],
                ['trait_type' => 'Section', 'value' => $ticket->section->code],
                ['trait_type' => 'Row', 'value' => $ticket->row_no],
                ['trait_type' => 'Seat', 'value' => $ticket->seat_no],
                ['trait_type' => 'Transferable', 'value' => 'No'],
            ],
        ];
    }

    /** 입장 코드: 티켓번호 + 30초 창 + 서명. 화면이 25초마다 새로 받아 간다. */
    public function entryCode(Ticket $ticket, ?int $now = null): string
    {
        $window = intdiv($now ?? time(), self::ENTRY_WINDOW);

        return "SIREN1.{$ticket->ticket_no}.{$window}.".$this->sign($ticket->ticket_no, $window);
    }

    /** 검표: 서명·시간 창·토큰 상태 확인 후 한 번만 입장 처리 */
    public function checkIn(string $code, ?int $now = null): Ticket
    {
        $parts = explode('.', trim($code));
        if (count($parts) !== 4 || $parts[0] !== 'SIREN1') {
            throw new OrderException('SIREN 입장 코드가 아니에요.', 'bad_code');
        }
        [, $ticketNo, $window, $sig] = $parts;
        $current = intdiv($now ?? time(), self::ENTRY_WINDOW);
        if (! ctype_digit($window) || ! in_array((int) $window, [$current, $current - 1], true)) {
            throw new OrderException('만료된 입장 코드예요. 팬 화면을 새로 열어 달라고 해 주세요. (캡처 화면일 수 있어요)', 'expired_code');
        }
        if (! hash_equals($this->sign($ticketNo, (int) $window), $sig)) {
            throw new OrderException('위조된 입장 코드예요.', 'forged_code');
        }

        return DB::transaction(function () use ($ticketNo) {
            $ticket = Ticket::with(['event', 'section', 'token'])->where('ticket_no', $ticketNo)->lockForUpdate()->firstOrFail();
            if ($ticket->event->isCancelled()) {
                throw new OrderException('취소된 공연의 티켓이에요.', 'cancelled');
            }
            if ($ticket->status === 'used') {
                throw new OrderException('이미 입장한 티켓이에요. ('.$ticket->checked_in_at?->format('H:i:s').' 입장)', 'already_used', 409);
            }
            if ($ticket->status !== 'paid' || ($ticket->token && $ticket->token->status !== 'minted')) {
                throw new OrderException('쓸 수 없는 티켓이에요 (취소·소각됨).', 'invalid');
            }
            $ticket->update(['status' => 'used', 'checked_in_at' => now()]);
            $ticket->token?->update(['status' => 'used', 'used_at' => now()]);

            return $ticket;
        });
    }

    private function sign(string $ticketNo, int $window): string
    {
        return substr(hash_hmac('sha256', "entry|{$ticketNo}|{$window}", (string) config('app.key')), 0, 16);
    }
}
