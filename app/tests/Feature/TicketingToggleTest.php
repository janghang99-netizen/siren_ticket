<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 슈퍼관리자 '공연 티켓 판매' 켜기/끄기 — 끄면 예매·내 티켓·좌석표 메뉴가 숨고 매장 주문은 그대로 */
class TicketingToggleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'super_admin']);
        $this->event = Event::create(['slug' => 'show', 'name' => '공연', 'artist' => '그룹', 'starts_at' => now()->addDays(3), 'sales_open_at' => now()->subHour(), 'max_per_buyer' => 4]);
        Store::create(['code' => 's1', 'name' => '아레나 커피']);
    }

    public function test_default_is_off_and_admin_can_toggle(): void
    {
        Setting::query()->whereKey('ticketing_enabled')->delete();
        $this->assertFalse(Setting::ticketingEnabled());   // 저장값이 없으면 기본은 끔

        $this->actingAs($this->admin)->get(route('admin.settings'))->assertOk()->assertSee('공연 티켓 판매')->assertSee('꺼짐');
        $this->post(route('admin.settings.ticketing'), ['enabled' => 1])->assertSessionHas('status', fn ($s) => str_contains($s, '켰어요'));
        $this->assertTrue(Setting::ticketingEnabled());
        $this->post(route('admin.settings.ticketing'), ['enabled' => 0]);
        $this->assertFalse(Setting::ticketingEnabled());
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.ticketing']);
    }

    public function test_when_off_ticket_screens_are_hidden_and_store_order_stays(): void
    {
        Setting::put(['ticketing_enabled' => '0']);

        // 팬: 예매·내 티켓은 막히고 매장 주문으로
        $this->get(route('concerts.show', $this->event))->assertRedirect(route('stores.index', $this->event));
        $this->get(route('tickets'))->assertRedirect(route('shop.index'));
        $home = $this->get(route('home'))->assertOk();
        $home->assertDontSee('좌석 고르기')->assertDontSee('내 티켓')->assertSee('매장주문');
        $this->get(route('stores.index', $this->event))->assertOk();

        // 운영사: 티켓 판매·입장 검표·공연장 메뉴 숨김, 들어가면 설정으로
        $this->actingAs($this->admin);
        $events = $this->get(route('admin.events.index'))->assertOk();
        $events->assertDontSee('티켓 판매')->assertDontSee('입장 검표')->assertSee('매장 주문 페이지');
        $this->get(route('admin.tickets.index'))->assertRedirect(route('admin.settings'));
        $this->get(route('admin.venues.index'))->assertRedirect(route('admin.settings'));
        $this->get(route('admin.check-in'))->assertRedirect(route('admin.settings'));

        // 공연 등록은 좌석 설정 없이 (매장 주문 시간대만)
        $this->get(route('admin.events.create'))->assertOk()->assertDontSee('좌석 등급 · 가격');
        $this->post(route('admin.events.store'), ['artist' => '새 그룹', 'venue' => '고양종합운동장', 'name' => '팬미팅',
            'starts_at' => now()->addMonth()->format('Y-m-d\TH:i'), 'theme_color' => '#6C4BFF', 'max_per_buyer' => 4])->assertSessionHasNoErrors();
        $new = Event::where('artist', '새 그룹')->firstOrFail();
        $this->assertSame(0, $new->sections()->count());
        $this->assertSame(3, $new->capacitySlots()->count());   // 매장 1곳 × 시간대 3개

        // 다시 켜면 그대로 보인다
        Setting::put(['ticketing_enabled' => '1']);
        $this->get(route('admin.tickets.index'))->assertOk();
        $this->get(route('concerts.show', $this->event))->assertOk();
    }
}
