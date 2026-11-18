<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\SeatSection;
use App\Models\User;
use App\Services\Nft\NftTicketService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** 대규모 검표: 검표원 계정 · 휴대폰 검표(공연 확인·게이트 기록) · 블록체인 ‘사용됨’은 응답 뒤 한 줄로 */
class GateCheckInTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;
    private SeatSection $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->event = Event::create(['slug' => 'gate-show', 'name' => '월드투어', 'artist' => '아스테리아', 'venue' => '서울 아레나',
            'starts_at' => now()->addDays(5), 'sales_open_at' => now()->subDay(), 'max_per_buyer' => 4]);
        $this->section = SeatSection::create(['event_id' => $this->event->id, 'code' => 'A1', 'name' => 'A1', 'tier' => 'R', 'price' => 99000,
            'capacity' => 50, 'ring' => 1, 'start_deg' => 0, 'end_deg' => 30]);
    }

    private function ticket(?Event $event = null, ?SeatSection $section = null)
    {
        $m = $this->makeMember('0109'.random_int(1000000, 9999999));

        return app(TicketService::class)->purchase($event ?? $this->event, $section ?? $this->section, 1, $m->name, $m->phone, now(), member: $m)->first();
    }

    private function gateUser(): User
    {
        return User::factory()->create(['role' => 'gate', 'gate_name' => '1번 게이트']);
    }

    public function test_gate_staff_logs_in_to_scanner_only(): void
    {
        $gate = $this->gateUser();
        $this->post(route('login'), ['email' => $gate->email, 'password' => 'password'])->assertRedirect(route('gate.scan'));
        $page = $this->get(route('gate.scan'))->assertOk()->assertSee('카메라로 검표 시작');
        $this->assertSame($this->event->id, $page->viewData('events')->first()['id']);   // 고를 수 있는 공연
        $this->get(route('admin.events.index'))->assertForbidden();
        $this->get(route('admin.customers.index'))->assertForbidden();
    }

    public function test_only_gate_staff_and_super_admin_can_scan(): void
    {
        $this->get(route('gate.scan'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'vendor']), 'web')->get(route('gate.scan'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']), 'web')->get(route('gate.scan'))->assertOk();
    }

    public function test_scan_admits_once_records_gate_and_rejects_other_show(): void
    {
        $t = $this->ticket();
        $code = app(NftTicketService::class)->entryCode($t);
        $gate = $this->gateUser();
        $this->actingAs($gate, 'web');

        $this->postJson(route('gate.scan.submit'), ['code' => $code, 'event_id' => $this->event->id])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('seat', $t->seatLabel())
            ->assertJsonPath('stats.entered', 1)->assertJsonPath('stats.total', 1);
        $t->refresh();
        $this->assertSame('used', $t->status);
        $this->assertSame($gate->id, $t->checked_in_by);
        $this->assertSame('1번 게이트', $t->checked_in_gate);

        // 다른 게이트에서 같은 티켓 → 이미 입장 (어느 게이트에서 몇 시에)
        $dup = $this->postJson(route('gate.scan.submit'), ['code' => $code, 'event_id' => $this->event->id, 'gate' => '3번 게이트'])
            ->assertStatus(409)->assertJsonPath('reason', 'already_used')->assertJsonPath('ok', false);
        $this->assertStringContainsString('1번 게이트', $dup->json('message'));

        // 다른 공연 티켓은 거절
        $other = Event::create(['slug' => 'other', 'name' => '팬미팅', 'artist' => '노바', 'venue' => '서울 아레나',
            'starts_at' => now()->addDays(6), 'sales_open_at' => now()->subDay(), 'max_per_buyer' => 4]);
        $os = SeatSection::create(['event_id' => $other->id, 'code' => 'B1', 'name' => 'B1', 'tier' => 'S', 'price' => 50000, 'capacity' => 10, 'ring' => 1, 'start_deg' => 0, 'end_deg' => 30]);
        $t2 = $this->ticket($other, $os);
        $this->postJson(route('gate.scan.submit'), ['code' => app(NftTicketService::class)->entryCode($t2), 'event_id' => $this->event->id])
            ->assertStatus(422)->assertJsonPath('reason', 'wrong_event');
        $this->assertSame('paid', $t2->fresh()->status);

        $this->getJson(route('gate.stats', $this->event))->assertOk()->assertJson(['entered' => 1, 'total' => 1]);
    }

    public function test_chain_used_mark_is_sent_after_response_one_at_a_time(): void
    {
        $nft = app(NftTicketService::class);
        $t = $this->ticket();
        $this->assertNotNull($t->token->mint_tx);

        // 검표 자체는 체인을 기다리지 않는다 — 서비스 checkIn 은 DB 만 확정
        $nft->checkIn($nft->entryCode($t));
        $this->assertSame('used', $t->token->fresh()->status);
        $this->assertNull($t->token->fresh()->used_tx);

        // 누가 이미 보내는 중이면(잠금) 기다리지 않고 돌아간다 → 그쪽·다음 스케줄러가 보낸다
        $held = Cache::lock('nft:flush-used', 60);
        $this->assertTrue($held->get());
        $this->assertSame(0, $nft->flushUsed());
        $held->release();

        $this->assertSame(1, $nft->flushUsed());
        $this->assertNotNull($t->token->fresh()->used_tx);
        $this->assertSame(0, $nft->flushUsed());   // 보낼 게 없으면 0

        // 검표 화면으로 찍으면 응답 뒤에 바로 보낸다
        $t2 = $this->ticket();
        $this->actingAs($this->gateUser(), 'web')
            ->postJson(route('gate.scan.submit'), ['code' => $nft->entryCode($t2), 'event_id' => $this->event->id])->assertOk();
        $this->assertNotNull($t2->token->fresh()->used_tx);

        // 응답 뒤 전송이 실패해 밀린 기록은 검표 화면의 현황 새로고침이 이어 보낸다 (크론 없는 호스팅)
        $t3 = $this->ticket();
        $nft->checkIn($nft->entryCode($t3));
        $this->getJson(route('gate.stats', $this->event))->assertOk();
        $this->assertNotNull($t3->token->fresh()->used_tx);
    }

    public function test_super_admin_manages_gate_staff(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']), 'web');
        $this->get(route('admin.gate-staff'))->assertOk()->assertSee('검표원 계정 만들기');
        $this->post(route('admin.gate-staff.store'), ['name' => '김검표', 'email' => 'Gate1@Example.com', 'gate_name' => '2번 게이트', 'password' => 'gate-pass-01'])
            ->assertRedirect();
        $u = User::where('email', 'gate1@example.com')->firstOrFail();
        $this->assertTrue($u->isGate());
        $this->assertSame('2번 게이트', $u->gate_name);
        $this->get(route('admin.gate-staff'))->assertSee('김검표');

        $this->put(route('admin.gate-staff.update', $u), ['gate_name' => '5번 게이트'])->assertRedirect();
        $this->assertSame('5번 게이트', $u->fresh()->gate_name);
        $this->delete(route('admin.gate-staff.destroy', $u))->assertRedirect();
        $this->assertModelMissing($u);
    }

    public function test_fan_ticket_shows_entry_qr_button(): void
    {
        $m = $this->makeMember('01033334444');
        app(TicketService::class)->purchase($this->event, $this->section, 1, $m->name, $m->phone, now(), member: $m);
        $this->actingAsMember($m);
        $this->get(route('tickets'))->assertOk()->assertSee('입장 QR 보기');
    }
}
