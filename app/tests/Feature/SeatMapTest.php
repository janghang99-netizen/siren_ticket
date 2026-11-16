<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\SeatMap;
use App\Models\SeatSection;
use App\Models\User;
use App\Services\OrderException;
use App\Services\SeatMap\SeatMapReader;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 공연장 좌석표: 배치도 이미지 → (AI) 등급·구역 → 편집 → 공연 등록 → 팬이 좌석 직접 선택 */
class SeatMapTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'super_admin']);

        // AI 판독기 대신 정해진 결과 — 범례 2등급, 구역 3개(같은 이름 W1 두 번)
        $this->app->instance(SeatMapReader::class, new class implements SeatMapReader
        {
            public function ready(): bool
            {
                return true;
            }

            public function read(string $image, string $mediaType): array
            {
                return [
                    'tiers' => [
                        ['name' => 'Ground', 'color' => '#1e2a78', 'price' => 179000, 'seats' => 100],
                        ['name' => '하층부 (1F)', 'color' => 'F44', 'price' => 250000, 'seats' => 30],
                    ],
                    'sections' => [
                        ['code' => 'A', 'tier' => 0, 'x' => .4, 'y' => .3, 'w' => .1, 'h' => .2],
                        ['code' => 'W1', 'tier' => 1, 'x' => .7, 'y' => .3, 'w' => .05, 'h' => .05],
                        ['code' => 'W1', 'tier' => 1, 'x' => 1.4, 'y' => -.2, 'w' => .5, 'h' => .05],   // 범위 밖 좌표는 잘라낸다
                    ],
                ];
            }
        });
    }

    private function upload(bool $ai = true)
    {
        return $this->actingAs($this->admin)->post(route('admin.seat-maps.store'), [
            'venue' => '고양종합운동장', 'name' => '고양 · 가로 무대', 'use_ai' => $ai ? '1' : null,
            'image' => UploadedFile::fake()->image('map.png', 1200, 700),
        ]);
    }

    private function sectionPayload(array $sections, ?array $tiers = null): string
    {
        return json_encode([
            'tiers' => $tiers ?? [['key' => 't1', 'name' => '스탠딩석', 'color' => '#112233', 'price' => 150000, 'seats' => 0]],
            'sections' => $sections,
        ]);
    }

    public function test_uploading_a_seat_map_image_makes_a_section_list_with_ai(): void
    {
        $this->upload()->assertRedirect();
        $map = SeatMap::with('sections')->firstOrFail();

        $this->assertSame('ai', $map->read_by);
        Storage::disk('local')->assertExists($map->image_path);
        $this->assertSame(['Ground', '하층부 (1F)'], array_column($map->tiers, 'name'));
        $this->assertSame('#FF4444', $map->tiers[1]['color']);
        $this->assertSame(['A', 'W1', 'W1-2'], $map->sections->pluck('code')->all());   // 겹치는 이름은 -2
        $this->assertSame(['t1', 't2', 't2'], $map->sections->pluck('tier')->all());
        // 범례 좌석 수를 등급 구역에 나눔: Ground 100석 → A 5열×20, 1F 30석 → 2구역 15석씩
        $this->assertSame([5, 20], [$map->sections[0]->rows, $map->sections[0]->seats_per_row]);
        $this->assertSame([1, 15], [$map->sections[1]->rows, $map->sections[1]->seats_per_row]);
        $this->assertSame(130, $map->totalSeats());
        $this->assertLessThanOrEqual(1.0, $map->sections[2]->x + $map->sections[2]->w);

        $this->get(route('admin.seat-maps.edit', $map))->assertOk()->assertSee('객석 목록');
        $this->get(route('media.seat-map', basename($map->image_path)))->assertOk();
    }

    public function test_without_ai_the_admin_draws_sections_and_bad_input_is_rejected(): void
    {
        $this->upload(ai: false);
        $map = SeatMap::firstOrFail();
        $this->assertSame('manual', $map->read_by);
        $this->assertCount(0, $map->sections);

        // 구역 이름이 겹치면 저장하지 않는다
        $dup = [['code' => 'A', 'tier' => 't1', 'x' => .1, 'y' => .1, 'w' => .1, 'h' => .1, 'rows' => 2, 'seats_per_row' => 10]];
        $this->put(route('admin.seat-maps.update', $map), ['name' => $map->name, 'venue' => $map->venue, 'payload' => $this->sectionPayload([...$dup, ...$dup])])
            ->assertSessionHasErrors('payload');
        $this->assertCount(0, $map->fresh()->sections);

        $this->put(route('admin.seat-maps.update', $map), ['name' => '세로 무대', 'venue' => $map->venue, 'payload' => $this->sectionPayload([
            ...$dup,
            ['code' => 'B', 'tier' => 't1', 'x' => .3, 'y' => .1, 'w' => .1, 'h' => .1, 'rows' => 3, 'seats_per_row' => 5],
        ])])->assertSessionHasNoErrors();
        $this->assertSame(35, $map->fresh()->load('sections')->totalSeats());
        $this->assertSame('세로 무대', $map->fresh()->name);
    }

    /** 좌석표로 공연 등록 → 팬이 고른 좌석으로 발급, 같은 좌석은 두 번 못 사고, 취소하면 다시 판매 */
    public function test_event_from_seat_map_sells_chosen_seats_once(): void
    {
        $this->upload();
        $map = SeatMap::firstOrFail();

        $this->post(route('admin.events.store'), [
            'artist' => '테스트그룹', 'venue' => '고양종합운동장', 'name' => '투어',
            'starts_at' => now()->addMonth()->format('Y-m-d\TH:i'), 'sales_open_at' => now()->subHour()->format('Y-m-d\TH:i'),
            'theme_color' => '#6C4BFF', 'max_per_buyer' => 4, 'seat_map_id' => $map->id,
        ])->assertSessionHasNoErrors();

        $event = Event::with('sections')->latest('id')->firstOrFail();
        $this->assertSame($map->id, $event->seat_map_id);
        $this->assertCount(3, $event->sections);
        $a = $event->sections->firstWhere('code', 'A');
        $this->assertSame([179000, 100, 5, 20, '#1E2A78'], [$a->price, $a->capacity, $a->rows, $a->seats_per_row, $a->color]);

        // 팬 화면: 배치도 이미지와 좌석 현황
        $this->get(route('concerts.show', $event))->assertOk()->assertSee(basename($map->image_path));
        $this->getJson(route('concerts.seats', [$event, $a->id]))->assertOk()->assertJson(['rows' => 5, 'per_row' => 20, 'taken' => []]);

        // 회원이 2열 3·4번을 고른다
        $this->actingAsMember();
        $this->post(route('concerts.purchase', $event), ['section_id' => $a->id, 'qty' => 2, 'seats' => ['2-4', '2-3'], 'agree_terms' => '1'])
            ->assertRedirect(route('tickets'));
        $tickets = \App\Models\Ticket::where('seat_section_id', $a->id)->orderBy('seat_no')->get();
        $this->assertSame([[2, 3], [2, 4]], $tickets->map(fn ($t) => [$t->row_no, $t->seat_no])->all());
        $this->assertSame(2, $a->fresh()->sold);
        $this->getJson(route('concerts.seats', [$event, $a->id]))->assertJson(['taken' => ['2-3', '2-4'], 'left' => 98]);

        // 다른 사람이 같은 좌석을 사면 거절되고 판매 수도 그대로
        $svc = app(TicketService::class);
        try {
            $svc->purchase($event, $a->fresh(), 1, '다른팬', '01099998888', null, null, 0, null, ['2-3']);
            $this->fail('이미 팔린 좌석이 또 팔림');
        } catch (OrderException $e) {
            $this->assertSame('seat_taken', $e->reason);
        }
        $this->assertSame(2, $a->fresh()->sold);

        // 좌석 수와 매수가 다르거나 격자 밖 좌석이면 거절
        foreach ([[2, ['5-1']], [1, ['6-1']], [1, ['0-1']]] as [$qty, $seats]) {
            try {
                $svc->purchase($event, $a->fresh(), $qty, '다른팬', '01099998888', null, null, 0, null, $seats);
                $this->fail('잘못된 좌석이 팔림');
            } catch (OrderException) {
            }
        }

        // 취소하면 좌석이 풀려 다시 살 수 있다
        $svc->cancel($tickets->first());
        $this->assertSame(0, DB::table('taken_seats')->where('row_no', 2)->where('seat_no', 3)->count());
        $again = $svc->purchase($event, $a->fresh(), 1, '다른팬', '01099998888', null, null, 0, null, ['2-3']);
        $this->assertSame([2, 3], [$again->first()->row_no, $again->first()->seat_no]);
    }

    public function test_seat_map_used_by_an_event_cannot_be_deleted_and_bowl_events_still_work(): void
    {
        $this->upload();
        $map = SeatMap::firstOrFail();
        $event = Event::create(['slug' => 'e1', 'name' => '공연', 'artist' => '그룹', 'starts_at' => now()->addDay(), 'sales_open_at' => now()->subHour(), 'max_per_buyer' => 4]);
        app(\App\Services\SeatMap\SeatMapService::class)->applyToEvent($event, $map);

        $this->actingAs($this->admin)->delete(route('admin.seat-maps.destroy', $map))->assertSessionHasErrors('payload');
        $this->assertModelExists($map);

        // 보울 구역(격자 없음)은 좌석 없이 자동 배정 그대로
        $bowl = SeatSection::create(['event_id' => $event->id, 'code' => 'F1', 'name' => 'F1', 'tier' => 'VIP', 'price' => 1000,
            'capacity' => 5, 'ring' => 0, 'start_deg' => 0, 'end_deg' => 10]);
        $t = app(TicketService::class)->purchase($event, $bowl, 2, '팬', '01011112222');
        $this->assertSame([1, 2], $t->pluck('seat_no')->all());
        $this->getJson(route('concerts.seats', [$event, $bowl->id]))->assertNotFound();
    }
}
