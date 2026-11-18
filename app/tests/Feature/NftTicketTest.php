<?php

namespace Tests\Feature;

use App\Models\CustomerWallet;
use App\Models\Event;
use App\Models\SeatSection;
use App\Models\TicketToken;
use App\Models\User;
use App\Services\Nft\NftTicketService;
use App\Services\OrderException;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** NFT 티켓: 회원 예매 발행 · 취소 소각 · 30초 입장 코드 검표 */
class NftTicketTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;
    private SeatSection $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->event = Event::create(['slug' => 'nft-show', 'name' => '월드투어', 'artist' => '아스테리아', 'venue' => '서울 아레나',
            'starts_at' => now()->addDays(20), 'sales_open_at' => now()->subDay(), 'max_per_buyer' => 4]);
        $this->section = SeatSection::create(['event_id' => $this->event->id, 'code' => 'A1', 'name' => 'A1', 'tier' => 'R', 'price' => 99000,
            'capacity' => 50, 'ring' => 1, 'start_deg' => 0, 'end_deg' => 30]);
    }

    private function buy(int $qty = 1, ?\App\Models\Member $member = null)
    {
        $member ??= $this->makeMember();

        return app(TicketService::class)->purchase($this->event, $this->section, $qty, $member->name, $member->phone, now(), member: $member);
    }

    public function test_member_purchase_mints_non_transferable_token_to_customer_wallet(): void
    {
        $tickets = $this->buy(2);
        $this->assertSame(2, TicketToken::where('status', 'minted')->count());
        $token = $tickets[0]->token;
        $wallet = CustomerWallet::first();
        $this->assertSame($wallet->address, $token->owner_address);
        $this->assertSame(1, CustomerWallet::count());   // 같은 고객의 티켓은 같은 지갑으로
        $this->assertFalse($token->transferable);
        $this->assertStringStartsWith('0x', $token->mint_tx);
        $this->assertSame($tickets[0]->customer_id, $wallet->customer_id);   // 마일리지와 같은 고객 단위 지갑
    }

    public function test_metadata_is_public_and_has_no_personal_data(): void
    {
        $t = $this->buy()->first();
        $res = $this->getJson(route('nft.metadata', $t->token->token_id))->assertOk()
            ->assertJsonPath('attributes.8.value', 'No');
        $body = $res->getContent();
        $this->assertStringNotContainsString($t->holder_phone, $body);
        $this->assertStringNotContainsString(json_encode($t->holder_name), $body);
        $this->assertStringContainsString('아스테리아', $res->json('name'));
        $this->get(route('nft.image', $t->id))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    }

    public function test_cancel_burns_token(): void
    {
        $t = $this->buy()->first();
        app(TicketService::class)->cancelByBuyer($t);
        $this->assertSame('burned', $t->token->fresh()->status);
        $this->assertNotNull($t->token->fresh()->burn_tx);
    }

    public function test_entry_code_checks_in_once_and_rejects_replay_expired_and_forged(): void
    {
        $t = $this->buy()->first();
        $nft = app(NftTicketService::class);
        $now = time();
        $code = $nft->entryCode($t, $now);

        foreach ([
            [$code, $now + 120, 'expired_code'],                       // 캡처해 둔 지난 코드
            [substr($code, 0, -2).'zz', $now, 'forged_code'],          // 서명 위조
            ['SIREN1.'.$t->ticket_no.'.1.abc', $now, 'expired_code'],
            ['hello', $now, 'bad_code'],
        ] as [$bad, $at, $reason]) {
            try {
                $nft->checkIn($bad, $at);
                $this->fail("통과하면 안 되는 코드: {$reason}");
            } catch (OrderException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }

        $this->assertSame('used', $nft->checkIn($code, $now)->status);
        $this->assertSame('used', $t->token->fresh()->status);
        try {
            $nft->checkIn($nft->entryCode($t, $now), $now);   // 새 코드여도 두 번 입장 불가
            $this->fail('두 번 입장됨');
        } catch (OrderException $e) {
            $this->assertSame('already_used', $e->reason);
        }
    }

    public function test_only_owner_member_gets_entry_code_and_admin_scans(): void
    {
        $owner = $this->makeMember('01011110000');
        $t = $this->buy(1, $owner)->first();

        $this->actingAsMember($this->makeMember('01022220000'));
        $this->getJson(route('tickets.entry-code', $t))->assertNotFound();

        $this->actingAsMember($owner);
        $code = $this->getJson(route('tickets.entry-code', $t))->assertOk()->json('code');
        $this->assertStringStartsWith('SIREN1.', $code);

        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'web')->get(route('admin.check-in'))->assertOk();
        $this->postJson(route('admin.check-in.scan'), ['code' => $code])->assertOk()->assertJsonPath('ok', true);
        $this->postJson(route('admin.check-in.scan'), ['code' => $code])->assertStatus(422);
        $this->get(route('admin.check-in'))->assertSee('A1구역');
    }

    public function test_tickets_page_shows_nft_for_member(): void
    {
        $member = $this->makeMember();
        $t = $this->buy(1, $member)->first();
        $this->actingAsMember($member);
        $this->get(route('tickets'))->assertOk()->assertSee('NFT 티켓')->assertSee('#'.$t->token->token_id)
            ->assertSee('블록체인 인증 티켓');

        // 블록체인(카이아)에 기록된 토큰: 발행·입장 기록, NFT 보기, 내 지갑 기록 — 공식 탐색기 링크
        if ($t->token->network === 'local') {
            $this->get(route('tickets'))->assertSee('다담다 서버에 기록돼요');
        }
        $t->token->update(['network' => 'kaia-kairos', 'contract' => '0xabc', 'mint_tx' => '0xmint1', 'used_tx' => '0xused1', 'used_at' => now(), 'status' => 'used']);
        $base = rtrim(config('siren.nft.kaia.explorer'), '/');
        $this->get(route('tickets'))->assertOk()
            ->assertSee('카이아 테스트넷')
            ->assertSee($base.'/tx/0xmint1', false)->assertSee($base.'/tx/0xused1', false)
            ->assertSee($base.'/nft/0xabc/'.$t->token->token_id, false)
            ->assertSee($base.'/address/'.$t->token->owner_address, false);
    }
}
