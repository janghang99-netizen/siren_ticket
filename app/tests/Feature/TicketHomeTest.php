<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Production;
use App\Services\VenueSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 공연 기능을 켠 메인 — 작품 + 회차 · 배너 · 오픈 예정 · 랭킹 · 장르 · 검색 */
class TicketHomeTest extends TestCase
{
    use RefreshDatabase;

    private function show(string $title, string $genre, $starts, $opens = null, string $venue = '서울아레나'): Production
    {
        $p = Production::create(['slug' => 'p'.\Illuminate\Support\Str::random(8), 'title' => $title, 'artist' => $title, 'genre' => $genre, 'venue' => $venue]);
        $e = Event::create(['production_id' => $p->id, 'slug' => $p->slug.'-1', 'name' => $title, 'artist' => $title, 'venue' => $venue,
            'starts_at' => $starts, 'sales_open_at' => $opens ?? now()->subDay(), 'status' => 'scheduled']);
        app(VenueSetup::class)->createSections($e, ['VIP' => ['price' => 150000, 'capacity' => 10], 'R' => ['price' => 99000, 'capacity' => 10], 'S' => ['price' => 77000, 'capacity' => 10]]);

        return $p->fresh(['events.sections']);
    }

    public function test_event_without_production_gets_one(): void
    {
        $e = Event::create(['slug' => 'solo', 'name' => '단독 공연', 'artist' => '솔로', 'venue' => '서울아레나', 'starts_at' => now()->addWeek(), 'status' => 'scheduled']);
        $this->assertNotNull($e->production_id);
        $this->assertSame('단독 공연', $e->production->title);
    }

    public function test_home_shows_open_soon_and_ranking_by_recent_sales(): void
    {
        $hot = $this->show('인기 콘서트', 'concert', now()->addDays(20));
        $this->show('조용한 연극', 'theater', now()->addDays(10));
        $later = $this->show('오픈 예정 뮤지컬', 'musical', now()->addDays(40), now()->addDays(2)->setTime(14, 0));
        // 최근 판매: 인기 콘서트 3장
        $e = $hot->events->first();
        foreach ([1, 2, 3] as $n) {
            DB::table('tickets')->insert(['event_id' => $e->id, 'seat_section_id' => $e->sections->first()->id, 'ticket_no' => 'T'.$n,
                'row_no' => 1, 'seat_no' => $n, 'holder_name' => '팬', 'holder_phone' => '01000000000', 'price' => 99000, 'status' => 'paid',
                'created_at' => now()->subDay(), 'updated_at' => now()]);
        }

        $html = $this->get('/')->assertOk()
            ->assertSee('티켓 오픈 예정')->assertSee('오픈 예정 뮤지컬')
            ->assertSee('77,000')                                          // 최저가
            ->assertSee(route('shows.show', $hot), false)
            ->getContent();
        $rank = substr($html, strpos($html, 'rank-title'));
        $this->assertLessThan(strpos($rank, '조용한 연극'), strpos($rank, '인기 콘서트'));   // 많이 팔린 작품이 앞
        $this->assertTrue($later->opensLater());
    }

    public function test_genre_page_and_search(): void
    {
        $this->show('뮤지컬 레베카', 'musical', now()->addDays(5), null, '충무아트센터');
        $this->show('록 페스티벌', 'festival', now()->addDays(6));

        $this->get(route('shows.genre', 'musical'))->assertOk()->assertSee('뮤지컬 레베카')->assertDontSee('록 페스티벌');
        $this->get(route('shows.genre', 'sports'))->assertOk()->assertSee('아직 예매할 수 있는 스포츠 공연이 없어요');
        $this->get(route('shows.search', ['q' => '충무']))->assertOk()->assertSee('뮤지컬 레베카')->assertDontSee('록 페스티벌');
        $this->get('/genre/unknown')->assertNotFound();
    }

    /** 작품 상세: 기본 정보 · 등급별 가격 · 회차(달력 데이터) · 좌석 선택으로 가는 예매 링크 */
    public function test_production_detail_lists_rounds_and_prices(): void
    {
        $p = $this->show('겨울 콘서트', 'concert', now()->addDays(8)->setTime(19, 0));
        $p->update(['runtime_minutes' => 120, 'age_rating' => '8세 이상 관람가']);
        $second = Event::create(['production_id' => $p->id, 'slug' => $p->slug.'-2', 'name' => '겨울 콘서트', 'artist' => '겨울 콘서트', 'venue' => '서울아레나',
            'starts_at' => now()->addDays(9)->setTime(18, 0), 'sales_open_at' => now()->subDay(), 'status' => 'scheduled']);
        app(VenueSetup::class)->createSections($second, ['VIP' => ['price' => 150000, 'capacity' => 10], 'R' => ['price' => 99000, 'capacity' => 10], 'S' => ['price' => 77000, 'capacity' => 10]]);

        $this->get(route('shows.show', $p))->assertOk()
            ->assertSee('겨울 콘서트')->assertSee('120분')->assertSee('8세 이상 관람가')
            ->assertSee('VIP석')->assertSee('150,000')->assertSee('77,000')
            ->assertSee('지금 예매할 수 있어요')
            ->assertSee($second->slug, false)   // 둘째 회차도 달력 데이터(좌석 선택 주소)에
            ->assertSee('관람일 10일 전까지');

        // 끝난 작품(다가오는 회차 없음)은 없음
        $past = $this->show('지난 공연', 'concert', now()->subDay());
        $this->get(route('shows.show', $past))->assertNotFound();
    }

    public function test_production_detail_shows_open_time_before_sales(): void
    {
        $p = $this->show('오픈 전 뮤지컬', 'musical', now()->addDays(30), now()->addDays(3)->setTime(14, 0));
        $this->get(route('shows.show', $p))->assertOk()->assertSee('14:00 오픈')->assertDontSee('지금 예매할 수 있어요');
    }

    public function test_portal_pages_are_off_when_ticketing_is_off(): void
    {
        \App\Models\Setting::put(['ticketing_enabled' => '0']);
        $this->show('뮤지컬 레베카', 'musical', now()->addDays(5));

        $this->get(route('shows.genre', 'musical'))->assertRedirect();
        $this->get('/')->assertOk()->assertDontSee('뮤지컬 레베카');
    }

    public function test_samples_command_creates_productions_with_images(): void
    {
        Storage::fake('local');
        $dir = sys_get_temp_dir().'/siren-samples-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/poster.webp', 'img');
        file_put_contents($dir.'/manifest.json', json_encode([[
            'key' => 'demo', 'title' => 'DEMO LIVE', 'artist' => 'Demo', 'genre' => 'concert', 'venue' => '고양종합운동장',
            'badge' => '샘플', 'featured' => true, 'poster' => 'poster.webp',
            'dates' => [now()->addMonth()->format('Y-m-d 19:00'), now()->addMonth()->addDay()->format('Y-m-d 18:00')],
            'sales_open' => now()->addDays(3)->format('Y-m-d 14:00'),
            'tiers' => ['VIP' => [200000, 10], 'R' => [150000, 10], 'S' => [100000, 10]],
        ]]));

        $this->artisan('tickets:samples', ['dir' => $dir])->expectsOutputToContain('새로 넣은 샘플 작품 1개')->assertSuccessful();
        $this->artisan('tickets:samples', ['dir' => $dir])->expectsOutputToContain('이미 있음')->assertSuccessful();   // 다시 돌려도 중복 없음

        $p = Production::where('slug', 'sample-demo')->with('events.sections')->firstOrFail();
        $this->assertCount(2, $p->events);
        $this->assertSame(100000, $p->lowestPrice());
        $this->assertTrue($p->opensLater());
        Storage::disk('local')->assertExists($p->poster_path);
        $this->get(route('media.production', basename($p->poster_path)))->assertOk();
        $this->get('/')->assertSee('DEMO LIVE')->assertSee('샘플');
    }
}
