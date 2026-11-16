<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\SeatMap;
use App\Models\SeatSection;
use App\Models\User;
use App\Models\Venue;
use App\Services\OrderException;
use App\Services\SeatMap\SeatMapService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 좌석 리스트(CSV)로 실제 좌석 + 공연별 판매 제외·보류 좌석 */
class SeatListAndBlockTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SeatMap $map;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'super_admin']);
        $venue = Venue::create(['name' => '체조경기장', 'seats' => 70]);
        $this->map = SeatMap::create(['venue_id' => $venue->id, 'venue' => $venue->name, 'name' => '가로', 'image_path' => 'seat-maps/x.png',
            'image_w' => 100, 'image_h' => 100, 'tiers' => [['key' => 't1', 'name' => 'VIP석', 'color' => '#111111', 'price' => 100000, 'seats' => 0]]]);
        $this->map->sections()->create(['code' => 'A', 'tier' => 't1', 'x' => .4, 'y' => .3, 'w' => .1, 'h' => .1, 'rows' => 10, 'seats_per_row' => 20]);
    }

    private function upload(string $csv, string $name = 'seats.csv')
    {
        return $this->actingAs($this->admin)->post(route('admin.seat-maps.seat-list', $this->map),
            ['seat_list' => UploadedFile::fake()->createWithContent($name, $csv)]);
    }

    public function test_csv_seat_list_makes_real_rows(): void
    {
        $csv = "구역,열,번호,등급,가격\nA,1,1-20,VIP석,198000\nA,2,1-22,VIP석,198000\nA,3,3-24,VIP석,198000\nE1,A,1-12,R석,165000\nE1,B,1~14,R석,165000\nE1구역,C열,15번,R석,165000\n";
        $this->upload($csv)->assertRedirect()->assertSessionHas('status', fn ($s) => str_contains($s, '91석'));

        $map = $this->map->fresh()->load('sections');
        $a = $map->sections->firstWhere('code', 'A');
        $this->assertSame([20, 22, 22], array_map(fn ($r) => count($r['seats']), $a->layout));   // 열마다 다른 좌석 수
        $this->assertSame(3, $a->layout[2]['seats'][0]);                                           // 3열은 3번부터
        $this->assertSame([3, 22, 64], [$a->rows, $a->seats_per_row, $a->capacity()]);
        $e1 = $map->sections->firstWhere('code', 'E1');
        $this->assertSame(['A', 'B', 'C'], array_column($e1->layout, 'label'));
        $this->assertSame(27, $e1->capacity());
        // 등급·가격: 있는 등급은 가격만, 없는 등급은 새로
        $this->assertSame([['VIP석', 198000, 64], ['R석', 165000, 27]], array_map(fn ($t) => [$t['name'], $t['price'], $t['seats']], $map->tiers));

        // 편집 화면에서 저장해도 좌석 리스트는 남는다 (위치만 바뀜)
        $payload = json_encode(['tiers' => $map->tiers, 'sections' => [
            ['code' => 'A', 'tier' => 't1', 'x' => .2, 'y' => .2, 'w' => .1, 'h' => .1, 'rows' => 3, 'seats_per_row' => 22],
            ['code' => 'E1', 'tier' => $e1->tier, 'x' => .5, 'y' => .5, 'w' => .1, 'h' => .1, 'rows' => 3, 'seats_per_row' => 15],
        ]]);
        $this->put(route('admin.seat-maps.update', $this->map), ['name' => '가로', 'venue_id' => $this->map->venue_id, 'payload' => $payload])->assertSessionHasNoErrors();
        $this->assertSame(91, $this->map->fresh()->load('sections')->totalSeats());
        $this->assertSame(.2, $this->map->fresh()->sections->firstWhere('code', 'A')->x);

        $this->get(route('admin.seat-maps.seat-list-sample'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_excel_korean_csv_without_header_and_bad_lines(): void
    {
        $this->upload(mb_convert_encoding("B,1,1-10\nB,2,1-12\n", 'CP949', 'UTF-8'))->assertSessionHasNoErrors();
        $this->assertSame(22, $this->map->fresh()->load('sections')->sections->firstWhere('code', 'B')->capacity());

        $this->upload("구역,열,번호\nC,1,abc\n")->assertSessionHasErrors('seat_list');
        $this->assertNull($this->map->fresh()->sections()->where('code', 'C')->first());
    }

    private function eventFromMap(): Event
    {
        $this->upload("구역,열,번호,등급,가격\nA,1,1-5,VIP석,100000\nA,2,3-8,VIP석,100000\n");
        $event = Event::create(['slug' => 'show', 'name' => '공연', 'artist' => '그룹', 'starts_at' => now()->addMonth(), 'sales_open_at' => now()->subHour(), 'max_per_buyer' => 4]);
        app(SeatMapService::class)->applyToEvent($event, $this->map->fresh());

        return $event;
    }

    public function test_purchase_uses_real_seat_numbers_and_row_names(): void
    {
        $event = $this->eventFromMap();
        $a = SeatSection::where('event_id', $event->id)->where('code', 'A')->firstOrFail();
        $this->assertSame(11, $a->capacity);
        $this->getJson(route('concerts.seats', [$event, $a->id]))->assertOk()->assertJsonPath('layout.1.seats.0', 3);

        $svc = app(TicketService::class);
        $t = $svc->purchase($event, $a, 1, '팬', '01011112222', null, null, 0, null, ['2-8'])->first();
        $this->assertSame(['2', 8, 'A구역 2열 8번'], [$t->row_label, $t->seat_no, $t->seatLabel()]);

        $this->expectException(OrderException::class);   // 2열은 3번부터 — 1번 좌석은 없다
        $svc->purchase($event, $a->fresh(), 1, '팬2', '01033334444', null, null, 0, null, ['2-1']);
    }

    public function test_admin_blocks_seats_kill_and_hold(): void
    {
        $event = $this->eventFromMap();
        $a = SeatSection::where('event_id', $event->id)->where('code', 'A')->firstOrFail();
        app(TicketService::class)->purchase($event, $a, 1, '팬', '01011112222', null, null, 0, null, ['1-1']);

        $this->actingAs($this->admin)->get(route('admin.events.seats', $event->id))->assertOk()->assertSee('좌석 막기');
        // 판매 제외: 1열 1~3번 (1번은 이미 팔려 건너뜀)
        $this->post(route('admin.events.seats.update', $event->id), ['section_id' => $a->id, 'action' => 'kill', 'seats' => ['1-1', '1-2', '1-3'], 'note' => '카메라석'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '2석') && str_contains($s, '이미 팔린 1석'));
        $this->post(route('admin.events.seats.update', $event->id), ['section_id' => $a->id, 'action' => 'hold', 'seats' => ['2-3', '2-4']]);
        $a->refresh();
        $this->assertSame([4, 1, 6], [$a->blocked, $a->sold, $a->remaining()]);   // 11 - 1 - 4
        $this->getJson(route('admin.events.seats.section', [$event->id, $a->id]))->assertJsonPath('blocks.1-2.kind', 'kill')->assertJsonPath('blocks.2-3.kind', 'hold');

        // 팬에게는 막은 좌석도 판매됨으로, 사려 해도 안 된다
        $taken = $this->getJson(route('concerts.seats', [$event, $a->id]))->json('taken');
        $this->assertEqualsCanonicalizing(['1-1', '1-2', '1-3', '2-3', '2-4'], $taken);
        try {
            app(TicketService::class)->purchase($event, $a, 1, '팬2', '01033334444', null, null, 0, null, ['2-3']);
            $this->fail('보류석이 팔림');
        } catch (OrderException $e) {
            $this->assertSame('seat_taken', $e->reason);
        }

        // 보류 풀면 다시 판매
        $this->post(route('admin.events.seats.update', $event->id), ['section_id' => $a->id, 'action' => 'release', 'seats' => ['2-3', '2-4']]);
        $this->assertSame(2, $a->fresh()->blocked);
        $t = app(TicketService::class)->purchase($event, $a->fresh(), 1, '팬2', '01033334444', null, null, 0, null, ['2-3']);
        $this->assertSame(3, $t->first()->seat_no);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'event.seat_block')->where('summary', 'like', '%막았어요%')->count());
    }
}
