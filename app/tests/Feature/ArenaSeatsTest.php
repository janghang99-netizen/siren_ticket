<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Services\TicketService;
use App\Services\VenueSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 전체 좌석도: 원형 아레나 구역 모양대로 좌석을 만들고, 팔린 좌석을 한 번에 내려준다 */
class ArenaSeatsTest extends TestCase
{
    use RefreshDatabase;

    private function arena(): Event
    {
        $e = Event::create(['slug' => 'arena-1', 'name' => '아레나 공연', 'artist' => '밴드', 'venue' => '서울아레나',
            'starts_at' => now()->addMonth(), 'sales_open_at' => now()->subDay(), 'status' => 'scheduled', 'max_per_buyer' => 4]);
        app(VenueSetup::class)->createSections($e, ['VIP' => ['price' => 150000, 'capacity' => 400], 'R' => ['price' => 120000, 'capacity' => 600], 'S' => ['price' => 90000, 'capacity' => 900]], -1);

        return $e->fresh('sections');
    }

    public function test_sections_follow_arc_shape(): void
    {
        $e = $this->arena();
        $s = $e->sections->firstWhere('tier', 'S');
        // 한 줄이 호를 따라 길다 (열당 좌석 > 열 수), 좌석 수 = 열 × 열당 좌석
        $this->assertGreaterThan($s->rows, $s->seats_per_row);
        $this->assertSame($s->rows * $s->seats_per_row, $s->capacity);
        $this->assertGreaterThanOrEqual(900, $s->capacity);
    }

    public function test_taken_lists_sold_seats_and_page_draws_arena(): void
    {
        $e = $this->arena();
        $s = $e->sections->first();
        app(TicketService::class)->purchase($e, $s, 2, '팬', '01011112222', now(), null, 0, null, ['1-1', '1-2']);

        $this->getJson(route('concerts.taken', $e))->assertOk()
            ->assertJsonPath('taken.'.$s->id, ['1-1', '1-2'])
            ->assertJsonPath('left.'.$s->id, $s->capacity - 2);

        $this->get(route('concerts.show', $e))->assertOk()->assertSee('id="arena-seats"', false)->assertSee('좌석 고르기');
    }
}
