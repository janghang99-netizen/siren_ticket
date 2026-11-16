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

    private \App\Models\Venue $venue;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'super_admin']);
        $this->venue = \App\Models\Venue::create(['name' => '고양종합운동장']);

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
                    'map_area' => ['x' => .3, 'y' => .08, 'w' => .43, 'h' => .9],   // 범례·요약표를 뺀 배치도 부분
                ];
            }
        });
    }

    private function upload(bool $ai = true)
    {
        return $this->actingAs($this->admin)->post(route('admin.seat-maps.store'), [
            'venue_id' => $this->venue->id, 'name' => '가로 무대', 'use_ai' => $ai ? '1' : null,
            'image' => UploadedFile::fake()->image('map.png', 1200, 700),
        ]);
    }

    private function sectionPayload(array $sections, ?array $tiers = null, ?array $crop = null): string
    {
        return json_encode([
            'tiers' => $tiers ?? [['key' => 't1', 'name' => '스탠딩석', 'color' => '#112233', 'price' => 150000, 'seats' => 0]],
            'sections' => $sections,
            'crop' => $crop,
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
        // 팬에게는 배치도 부분만 (범례·요약표 잘라 냄)
        $this->assertSame([.3, .08, .43, .9], [$map->crop_x, $map->crop_y, $map->crop_w, $map->crop_h]);
        $this->assertSame($this->venue->id, $map->venue_id);
        $this->assertSame('고양종합운동장', $map->venue);

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
        $this->put(route('admin.seat-maps.update', $map), ['name' => $map->name, 'venue_id' => $this->venue->id, 'payload' => $this->sectionPayload([...$dup, ...$dup])])
            ->assertSessionHasErrors('payload');
        $this->assertCount(0, $map->fresh()->sections);

        $this->put(route('admin.seat-maps.update', $map), ['name' => '세로 무대', 'venue_id' => $this->venue->id, 'payload' => $this->sectionPayload([
            ...$dup,
            ['code' => 'B', 'tier' => 't1', 'x' => .3, 'y' => .1, 'w' => .1, 'h' => .1, 'rows' => 3, 'seats_per_row' => 5],
        ])])->assertSessionHasNoErrors();
        $this->assertSame(35, $map->fresh()->load('sections')->totalSeats());
        $this->assertSame('세로 무대', $map->fresh()->name);

        // 보여줄 영역을 직접 자르기 (너무 작게 자르면 전체로)
        $one = [['code' => 'A', 'tier' => 't1', 'x' => .1, 'y' => .1, 'w' => .1, 'h' => .1, 'rows' => 2, 'seats_per_row' => 10]];
        $this->put(route('admin.seat-maps.update', $map), ['name' => '세로 무대', 'venue_id' => $this->venue->id,
            'payload' => $this->sectionPayload($one, null, ['x' => .2, 'y' => .1, 'w' => .5, 'h' => .8])])->assertSessionHasNoErrors();
        $this->assertSame([.2, .5], [$map->fresh()->crop_x, $map->fresh()->crop_w]);
        $this->put(route('admin.seat-maps.update', $map), ['name' => '세로 무대', 'venue_id' => $this->venue->id,
            'payload' => $this->sectionPayload($one, null, ['x' => .2, 'y' => .1, 'w' => .01, 'h' => .8])]);
        $this->assertSame([0.0, 1.0], [$map->fresh()->crop_x, $map->fresh()->crop_w]);
    }

    /** 새 배치도 이미지를 올리면 AI 가 객석 목록·보여줄 영역을 다시 만든다 */
    public function test_replacing_the_image_regenerates_the_section_list(): void
    {
        $this->upload(ai: false);
        $map = SeatMap::firstOrFail();
        $old = $map->image_path;

        $this->post(route('admin.seat-maps.image', $map), ['image' => UploadedFile::fake()->image('new.png', 1600, 900), 'use_ai' => '1'])
            ->assertRedirect(route('admin.seat-maps.edit', $map));
        $map->refresh()->load('sections');
        $this->assertNotSame($old, $map->image_path);
        Storage::disk('local')->assertMissing($old);   // 공연이 안 쓰는 옛 이미지는 지움
        $this->assertSame(['A', 'W1', 'W1-2'], $map->sections->pluck('code')->all());
        $this->assertSame(.3, $map->crop_x);
    }

    public function test_venue_list_groups_seat_maps_and_events_pick_a_venue(): void
    {
        $this->upload();
        $this->actingAs($this->admin)->get(route('admin.venues.index'))->assertOk()->assertSee('고양종합운동장')->assertSee('가로 무대');
        $this->get(route('admin.venues.show', $this->venue))->assertOk()->assertSee('가로 무대');
        $this->get(route('admin.seat-maps.index'))->assertRedirect(route('admin.venues.index'));

        $this->post(route('admin.venues.store'), ['name' => '고양종합운동장'])->assertSessionHasErrors('name');   // 같은 이름
        $this->post(route('admin.venues.store'), ['name' => 'KSPO DOME', 'address' => '서울 송파구'])->assertSessionHasNoErrors();
        $dome = \App\Models\Venue::where('name', 'KSPO DOME')->firstOrFail();

        // 좌석표 없는 공연장으로 기본 보울 공연 등록 → 공연장 연결
        $this->post(route('admin.events.store'), [
            'artist' => '그룹', 'venue_id' => $dome->id, 'name' => '팬미팅', 'starts_at' => now()->addMonth()->format('Y-m-d\TH:i'),
            'theme_color' => '#6C4BFF', 'max_per_buyer' => 4,
            'tiers' => ['VIP' => ['price' => 1000, 'capacity' => 10], 'R' => ['price' => 1000, 'capacity' => 10], 'S' => ['price' => 1000, 'capacity' => 10]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['KSPO DOME', $dome->id], [Event::latest('id')->first()->venue, Event::latest('id')->first()->venue_id]);

        // 공연장 이름을 바꾸면 공연 화면 이름도 바뀐다
        $this->put(route('admin.venues.update', $dome), ['name' => '케이스포돔'])->assertSessionHasNoErrors();
        $this->assertSame('케이스포돔', Event::latest('id')->first()->venue);
        // 공연이 있는 공연장은 지울 수 없다
        $this->delete(route('admin.venues.destroy', $dome))->assertSessionHasErrors('name');
    }

    /** 좌석표로 공연 등록 → 팬이 고른 좌석으로 발급, 같은 좌석은 두 번 못 사고, 취소하면 다시 판매 */
    public function test_event_from_seat_map_sells_chosen_seats_once(): void
    {
        $this->upload();
        $map = SeatMap::firstOrFail();

        $this->post(route('admin.events.store'), [
            'artist' => '테스트그룹', 'venue_id' => $this->venue->id, 'name' => '투어',
            'starts_at' => now()->addMonth()->format('Y-m-d\TH:i'), 'sales_open_at' => now()->subHour()->format('Y-m-d\TH:i'),
            'theme_color' => '#6C4BFF', 'max_per_buyer' => 4, 'seat_map_id' => $map->id,
        ])->assertSessionHasNoErrors();

        $event = Event::with('sections')->latest('id')->firstOrFail();
        $this->assertSame($map->id, $event->seat_map_id);
        $this->assertSame($this->venue->id, $event->venue_id);
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

        // 공연이 쓰는 좌석표에 새 이미지를 올리면 기존 것은 그대로 두고 새 좌석표를 만든다
        $oldImage = $map->image_path;
        $this->post(route('admin.seat-maps.image', $map), ['image' => UploadedFile::fake()->image('v2.png', 800, 600)]);
        $this->assertSame($oldImage, $map->fresh()->image_path);
        Storage::disk('local')->assertExists($oldImage);
        $copy = SeatMap::latest('id')->firstOrFail();
        $this->assertNotSame($map->id, $copy->id);
        $this->assertSame([$map->venue_id, 3], [$copy->venue_id, $copy->sections()->count()]);

        // 보울 구역(격자 없음)은 좌석 없이 자동 배정 그대로
        $bowl = SeatSection::create(['event_id' => $event->id, 'code' => 'F1', 'name' => 'F1', 'tier' => 'VIP', 'price' => 1000,
            'capacity' => 5, 'ring' => 0, 'start_deg' => 0, 'end_deg' => 10]);
        $t = app(TicketService::class)->purchase($event, $bowl, 2, '팬', '01011112222');
        $this->assertSame([1, 2], $t->pluck('seat_no')->all());
        $this->getJson(route('concerts.seats', [$event, $bowl->id]))->assertNotFound();
    }

    /** 공연장 등록 → 바로 배치도 올리기, 배치도가 없으면 객석 수만큼 임시 좌석표, 좌석 수 차이 경고 */
    public function test_venue_flow_temp_seat_map_and_seat_gap_warning(): void
    {
        $this->actingAs($this->admin)->post(route('admin.venues.store'), ['name' => '체조경기장', 'seats' => 15000])
            ->assertRedirect(route('admin.seat-maps.create', ['venue' => \App\Models\Venue::where('name', '체조경기장')->value('id')]));
        $dome = \App\Models\Venue::where('name', '체조경기장')->firstOrFail();
        $this->get(route('admin.seat-maps.create', ['venue' => $dome->id]))->assertOk()->assertSee('임시 좌석표 만들기');

        $this->post(route('admin.venues.temp-seat-map', $dome), ['seats' => 15000])->assertRedirect();
        $map = SeatMap::where('venue_id', $dome->id)->with('sections')->firstOrFail();
        $this->assertSame('temp', $map->read_by);
        $this->assertCount(8, $map->sections);                                   // 2,000석 이하 구역 8개
        $this->assertSame(15000, $map->totalSeats());
        $this->assertLessThanOrEqual(2000, $map->sections->max(fn ($s) => $s->capacity()));
        Storage::disk('local')->assertExists($map->image_path);
        $this->assertNull(app(\App\Services\SeatMap\SeatMapService::class)->seatGapNote($map));

        // 바로 공연 등록에 쓸 수 있다
        $this->post(route('admin.events.store'), [
            'artist' => '그룹', 'venue_id' => $dome->id, 'name' => '투어', 'starts_at' => now()->addMonth()->format('Y-m-d\TH:i'),
            'theme_color' => '#6C4BFF', 'max_per_buyer' => 4, 'seat_map_id' => $map->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame(15000, (int) \App\Models\Event::latest('id')->first()->sections()->sum('capacity'));

        // 좌석 수를 크게 바꾸면 저장할 때 경고
        $payload = json_encode(['tiers' => $map->tiers, 'sections' => [['code' => 'A1', 'tier' => 't1', 'x' => .1, 'y' => .2, 'w' => .2, 'h' => .2, 'rows' => 10, 'seats_per_row' => 20]]]);
        $this->put(route('admin.seat-maps.update', $map), ['name' => $map->name, 'venue_id' => $dome->id, 'payload' => $payload])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '공연장 객석 15,000석과'));
    }
}
