<?php

namespace App\Services\Nft;

use App\Models\Customer;
use App\Models\CustomerWallet;
use App\Models\Ticket;
use App\Models\TicketToken;
use App\Services\OrderException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * NFT 티켓: 회원 예매 → 발행, 취소 → 소각, 입장 → 사용 처리.
 *  - 체인 선택: 거주 국가가 체인 제외 국가(기본 중국)면 로컬 기록만, 아니면 설정된 체인(카이아 등).
 *  - 체인 전송이 실패해도 예매·취소·입장은 막지 않는다 → 토큰을 pending/오류로 남기고 nft:retry 가 다시 보낸다.
 *  - 입장 코드는 30초마다 바뀌는 서명 값이라 캡처로는 못 들어가고, 한 번 검표하면 다시 못 쓴다.
 *  - 메타데이터에는 공연·좌석만 넣는다 (체인은 지울 수 없다).
 */
class NftTicketService
{
    private const ENTRY_WINDOW = 30;

    public function __construct(private TicketChain $chain, private LocalTicketChain $local) {}

    /** 이 티켓을 기록할 체인 */
    public function chainFor(Ticket $ticket): TicketChain
    {
        $residence = $ticket->member?->residence_country;

        return in_array($residence, (array) config('siren.nft.offchain_residence', ['CN']), true) ? $this->local : $this->chain;
    }

    public function chainByNetwork(string $network): TicketChain
    {
        return $network === $this->local->network() ? $this->local : $this->chain;
    }

    /** 고객 지갑 (현재 체인 기준 하나). 개인키는 암호화해 저장 — 운영 단계에서는 KMS 로 옮긴다. */
    public function walletFor(Customer $customer, TicketChain $chain): CustomerWallet
    {
        $wallet = CustomerWallet::where('customer_id', $customer->id)->first();
        if ($wallet && $wallet->network === $chain->network()) {
            return $wallet;
        }
        $made = $chain->createWallet($customer->id);
        $attrs = ['network' => $chain->network(), 'address' => $made['address'], 'secret' => $made['secret']];

        return $wallet ? tap($wallet)->update($attrs) : CustomerWallet::create(['customer_id' => $customer->id] + $attrs);
    }

    /** 회원 티켓만 발행 (비회원 시절 티켓·관리자 발급분은 토큰 없음) */
    public function mint(Ticket $ticket): ?TicketToken
    {
        $ticket->loadMissing('member', 'customer', 'token');
        if (! $ticket->member_id || ! $ticket->customer_id || $ticket->status !== 'paid' || $ticket->token) {
            return $ticket->token;
        }
        $chain = $this->chainFor($ticket);
        $wallet = $this->walletFor($ticket->customer, $chain);
        $hash = hash('sha256', json_encode($this->metadata($ticket), JSON_UNESCAPED_UNICODE));

        $token = TicketToken::create([
            'ticket_id' => $ticket->id, 'network' => $chain->network(), 'contract' => $chain->contract(),
            'token_id' => (string) $ticket->id, 'owner_address' => $wallet->address, 'status' => 'pending', 'transferable' => false,
            'metadata_hash' => $hash,
        ]);
        $this->pushMint($token);

        return $token->fresh();
    }

    /** 체인에 발행 전송 (실패하면 pending 으로 두고 오류를 남긴다) */
    public function pushMint(TicketToken $token): bool
    {
        try {
            $chain = $this->chainByNetwork($token->network);
            $tx = $chain->mint($token->token_id, $token->owner_address, route('nft.metadata', $token->token_id), $token->metadata_hash);
            $token->update(['status' => 'minted', 'mint_tx' => $tx, 'minted_at' => now(), 'last_error' => null, 'contract' => $token->contract ?: $chain->contract()]);

            return true;
        } catch (Throwable $e) {
            $token->update(['last_error' => mb_substr($e->getMessage(), 0, 250)]);
            report($e);

            return false;
        }
    }

    public function burn(Ticket $ticket): void
    {
        $token = TicketToken::where('ticket_id', $ticket->id)->whereIn('status', ['minted', 'pending'])->first();
        if (! $token) {
            return;
        }
        $wasMinted = $token->status === 'minted';
        $token->update(['status' => 'burned', 'burned_at' => now()]);
        if ($wasMinted) {
            $this->pushBurn($token);
        }
    }

    public function pushBurn(TicketToken $token): bool
    {
        try {
            $token->update(['burn_tx' => $this->chainByNetwork($token->network)->burn($token->token_id), 'last_error' => null]);

            return true;
        } catch (Throwable $e) {
            $token->update(['last_error' => mb_substr($e->getMessage(), 0, 250)]);
            report($e);

            return false;
        }
    }

    public function pushUsed(TicketToken $token): bool
    {
        try {
            $token->update(['used_tx' => $this->chainByNetwork($token->network)->markUsed($token->token_id), 'last_error' => null]);

            return true;
        } catch (Throwable $e) {
            $token->update(['last_error' => mb_substr($e->getMessage(), 0, 250)]);
            report($e);

            return false;
        }
    }

    /**
     * 체인 전송을 못 한 것들 다시 보내기 (스케줄러). 발행이 늦게 된 경우 소각·입장 표시도 이어서 보낸다.
     *
     * @return array{mint:int, burn:int, used:int}
     */
    public function retryPending(int $limit = 50): array
    {
        $done = ['mint' => 0, 'burn' => 0, 'used' => 0];
        foreach (TicketToken::where('status', 'pending')->limit($limit)->get() as $t) {
            $done['mint'] += (int) $this->pushMint($t);
        }
        foreach (TicketToken::where('status', 'burned')->whereNull('burn_tx')->whereNotNull('mint_tx')->limit($limit)->get() as $t) {
            $done['burn'] += (int) $this->pushBurn($t);
        }
        foreach (TicketToken::where('status', 'used')->whereNull('used_tx')->limit($limit)->get() as $t) {
            if (! $t->mint_tx && $this->pushMintOnly($t)) {
                $done['mint']++;
            }
            if ($t->fresh()->mint_tx) {
                $done['used'] += (int) $this->pushUsed($t);
            }
        }

        return $done;
    }

    /** 발행 전에 입장한 토큰: 상태는 used 로 두고 발행만 보낸다 */
    private function pushMintOnly(TicketToken $token): bool
    {
        try {
            $chain = $this->chainByNetwork($token->network);
            $token->update(['mint_tx' => $chain->mint($token->token_id, $token->owner_address, route('nft.metadata', $token->token_id), $token->metadata_hash),
                'minted_at' => now(), 'contract' => $token->contract ?: $chain->contract(), 'last_error' => null]);

            return true;
        } catch (Throwable $e) {
            $token->update(['last_error' => mb_substr($e->getMessage(), 0, 250)]);

            return false;
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

        $ticket = DB::transaction(function () use ($ticketNo) {
            $ticket = Ticket::with(['event', 'section', 'token'])->where('ticket_no', $ticketNo)->lockForUpdate()->firstOrFail();
            if ($ticket->event->isCancelled()) {
                throw new OrderException('취소된 공연의 티켓이에요.', 'cancelled');
            }
            if ($ticket->status === 'used') {
                throw new OrderException('이미 입장한 티켓이에요. ('.$ticket->checked_in_at?->format('H:i:s').' 입장)', 'already_used', 409);
            }
            if ($ticket->status !== 'paid' || ($ticket->token && ! in_array($ticket->token->status, ['minted', 'pending'], true))) {
                throw new OrderException('쓸 수 없는 티켓이에요 (취소·소각됨).', 'invalid');
            }
            $ticket->update(['status' => 'used', 'checked_in_at' => now()]);
            $ticket->token?->update(['status' => 'used', 'used_at' => now()]);

            return $ticket;
        });
        // 체인 기록은 입장을 막지 않게 커밋 뒤에 (발행 전이면 재시도 명령이 발행 후 처리)
        if ($ticket->token?->mint_tx) {
            $this->pushUsed($ticket->token);
        }

        return $ticket;
    }

    private function sign(string $ticketNo, int $window): string
    {
        return substr(hash_hmac('sha256', "entry|{$ticketNo}|{$window}", (string) config('app.key')), 0, 16);
    }
}
