<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\SeatMap;
use App\Models\User;
use App\Models\Venue;
use App\Services\SeatMap\SeatMapService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 공연장 도면 편집기 — 만들기 · 저장(사각·다각형·열별 좌석·글자 열 이름) · 공연 적용 · 팬 좌석 화면 */
class SeatPlanTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function payload(): array
    {
        return [
            'name' => '체조경기장 · 엔드 무대',
            'tiers' => [['key' => 'VIP', 'name' => 'VIP', 'color' => '#8B5CF6', 'price' => 150000], ['key' => 'R', 'name' => 'R', 'color' => '#3B82F6', 'price' => 99000]],
            'plan' => ['stage' => ['x' => 300, 'y' => 40, 'w' => 400, 'h' => 80, 'rot' => 0, 'label' => 'STAGE'],
                'objects' => [['x' => 400, 'y' => 500, 'w' => 200, 'h' => 30, 'rot' => 0, 'label' => 'CONSOLE']],
                'labels' => [['x' => 500, 'y' => 950, 'text' => '2F', 'size' => 30]]],
            'sections' => [
                // 사각, 열마다 좌석 수가 다르고 글자 열 이름
                ['code' => 'a', 'tier' => 'VIP', 'counts' => '8,9,10', 'shape' => ['type' => 'rect', 'x' => 100, 'y' => 200, 'w' => 200, 'h' => 90, 'rot' => 0, 'row1' => 'top', 'rowLabels' => 'alpha']],
                // 다각형: 열별 좌석 수는 편집기가 모양에서 계산해 보낸다
                ['code' => '203', 'tier' => 'R', 'counts' => '10,10,6', 'shape' => ['type' => 'poly', 'points' => [[600, 200], [800, 200], [800, 300], [700, 300]], 'gr' => 3, 'gc' => 10]],
            ],
        ];
    }

    public function test_admin_creates_and_saves_plan(): void
    {
        $venue = Venue::create(['name' => '체조경기장']);
        $this->actingAs($this->admin())->post(route('admin.venues.plans.store', $venue), ['name' => '체조경기장 · 엔드 무대'])
            ->assertRedirect();
        $map = SeatMap::where('kind', 'plan')->firstOrFail();
        $this->get(route('admin.seat-maps.plan', $map))->assertOk()->assertSee('planEditor', false);
        $this->get(route('admin.seat-maps.edit', $map))->assertRedirect(route('admin.seat-maps.plan', $map));   // 도면은 도면 편집기로

        $this->putJson(route('admin.seat-maps.plan.update', $map), $this->payload())->assertOk()->assertJsonPath('seats', 27 + 26);
        $map->refresh()->load('sections');
        $a = $map->sections->firstWhere('code', 'A');                        // 구역 이름은 대문자로
        $this->assertSame(['A', 'B', 'C'], array_column($a->layout, 'label'));
        $this->assertSame([8, 9, 10], array_map(fn ($r) => count($r['seats']), $a->layout));
        $this->assertSame('poly', $map->sections->firstWhere('code', '203')->shape['type']);
        $this->assertSame('CONSOLE', $map->plan['objects'][0]['label']);
        $this->assertSame(27, collect($map->tiers)->firstWhere('key', 'VIP')['seats']);

        // 같은 구역 이름 두 번은 안 된다
        $bad = $this->payload();
        $bad['sections'][1]['code'] = 'A';
        $this->putJson(route('admin.seat-maps.plan.update', $map), $bad)->assertStatus(422);
    }

    public function test_vendor_cannot_open_plan_editor(): void
    {
        $venue = Venue::create(['name' => '체조경기장']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $this->actingAs($vendor)->post(route('admin.venues.plans.store', $venue), ['name' => 'x'])->assertForbidden();
    }

    public function test_plan_applied_to_event_draws_on_fan_page_and_sells_seats(): void
    {
        $venue = Venue::create(['name' => '체조경기장']);
        $plans = app(\App\Services\SeatMap\SeatPlanService::class);
        $map = $plans->create($venue, '도면');
        $plans->save($map, $this->payload());
        $event = Event::create(['slug' => 'plan-live', 'name' => '도면 공연', 'artist' => '밴드', 'venue' => $venue->name, 'venue_id' => $venue->id,
            'starts_at' => now()->addMonth(), 'sales_open_at' => now()->subDay(), 'status' => 'scheduled', 'max_per_buyer' => 4]);
        app(SeatMapService::class)->applyToEvent($event, $map->fresh('sections'));

        $a = $event->sections()->where('code', 'A')->firstOrFail();
        $this->assertSame('rect', $a->shape['type']);
        $this->assertTrue($a->hasSeat(3, 10));    // C열 10번 (3열은 10석)
        $this->assertFalse($a->hasSeat(1, 9));    // A열은 8석뿐

        app(TicketService::class)->purchase($event, $a, 1, '팬', '01022223333', now(), null, 0, null, ['3-10']);
        $this->getJson(route('concerts.taken', $event))->assertJsonPath('taken.'.$a->id, ['3-10']);
        $this->get(route('concerts.show', $event))->assertOk()->assertSee('id="plan-base"', false)->assertSee('CONSOLE');
    }

    public function test_blockchain_explainer_page(): void
    {
        $this->get(route('about.blockchain'))->assertOk()->assertSee('블록체인 위에 새겨집니다')->assertSee('암표로 되팔 수 없어요');
        // 공연이 있는 메인에 블록체인 티켓 띠
        Event::create(['slug' => 'live', 'name' => '공연', 'artist' => '밴드', 'venue' => '서울아레나', 'starts_at' => now()->addWeek(), 'status' => 'scheduled']);
        $this->get('/')->assertOk()->assertSee(route('about.blockchain'), false);
    }
}
