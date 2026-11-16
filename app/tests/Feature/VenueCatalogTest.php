<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Venue;
use App\Models\VenueCatalog;
use Database\Seeders\VenueCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 전국 공연장 DB: KOPIS 공연시설 받기 · 기본 목록 · 운영사 검색해서 불러오기 */
class VenueCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const LIST = '<?xml version="1.0" encoding="UTF-8"?><dbs>'
        .'<db><fcltynm>올림픽공원</fcltynm><mt10id>FC000025</mt10id><mt13cnt>6</mt13cnt><fcltychartr>공공(기타)</fcltychartr><sidonm>서울</sidonm><gugunnm>송파구</gugunnm><opende>1986</opende></db>'
        .'<db><fcltynm>세종문화회관</fcltynm><mt10id>FC000001</mt10id><mt13cnt>4</mt13cnt><fcltychartr>공공(문예회관)</fcltychartr><sidonm>서울</sidonm><gugunnm>종로구</gugunnm><opende>1978</opende></db>'
        .'</dbs>';

    private const DETAIL = '<?xml version="1.0" encoding="UTF-8"?><dbs><db>'
        .'<fcltynm>올림픽공원</fcltynm><mt10id>FC000025</mt10id><mt13cnt>2</mt13cnt><seatscale>18000</seatscale><telno>02-410-1114</telno>'
        .'<relateurl>https://www.ksponco.or.kr/olympicpark/</relateurl><adres>서울특별시 송파구 올림픽로 424</adres><la>37.5194</la><lo>127.1270</lo>'
        .'<mt13s><mt13><prfplcnm>KSPO DOME</prfplcnm><mt13id>FC000025-01</mt13id><seatscale>15000</seatscale></mt13>'
        .'<mt13><prfplcnm>올림픽홀</prfplcnm><mt13id>FC000025-02</mt13id><seatscale>3000</seatscale></mt13></mt13s>'
        .'</db></dbs>';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.kopis.key' => 'test-key']);
    }

    public function test_kopis_list_import_and_detail_on_pick(): void
    {
        Http::fake([
            'kopis.or.kr/openApi/restful/prfplc/*' => Http::response(self::DETAIL),
            'kopis.or.kr/openApi/restful/prfplc*' => Http::response(self::LIST),
        ]);
        $this->artisan('venues:import-kopis')->assertSuccessful();
        $this->assertSame(2, VenueCatalog::where('source', 'kopis')->count());
        $park = VenueCatalog::where('kopis_id', 'FC000025')->firstOrFail();
        $this->assertSame(['올림픽공원', '서울', '송파구'], [$park->name, $park->sido, $park->gugun]);
        $this->assertTrue($park->needsDetail());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'service=test-key') && str_contains($r->url(), 'rows=500'));

        // 다시 받아도 중복되지 않는다
        $this->artisan('venues:import-kopis')->assertSuccessful();
        $this->assertSame(2, VenueCatalog::count());

        // 운영사가 검색 → 고르면 그때 상세(주소·객석·홀)를 받아 온다
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->getJson(route('admin.venues.catalog', ['q' => '송파']))->assertOk()->assertJsonPath('0.name', '올림픽공원');
        $this->getJson(route('admin.venues.catalog.show', $park))->assertOk()
            ->assertJsonPath('address', '서울특별시 송파구 올림픽로 424')->assertJsonPath('seats', 18000)
            ->assertJsonPath('halls.0.name', 'KSPO DOME')->assertJsonPath('halls.0.seats', 15000);
        $this->assertEqualsWithDelta(37.5194, $park->fresh()->lat, 0.0001);

        // 불러온 값으로 공연장 추가 → 전국 DB 와 연결
        $this->post(route('admin.venues.store'), ['name' => '올림픽공원 KSPO DOME', 'address' => '서울특별시 송파구 올림픽로 424', 'seats' => 15000, 'catalog_id' => $park->id])
            ->assertSessionHasNoErrors();
        $venue = Venue::where('name', '올림픽공원 KSPO DOME')->firstOrFail();
        $this->assertSame([$park->id, 15000], [$venue->catalog_id, $venue->seats]);
        $this->get(route('admin.venues.index'))->assertOk()->assertSee('KOPIS 2곳 포함');
    }

    public function test_kopis_errors_and_missing_key(): void
    {
        config(['services.kopis.key' => null]);
        $this->artisan('venues:import-kopis')->expectsOutputToContain('KOPIS_API_KEY 가 없어요')->assertSuccessful();

        config(['services.kopis.key' => 'bad']);
        Http::fake(['*' => Http::response('<?xml version="1.0"?><dbs><db><returncode>02</returncode><errmsg>SERVICE KEY IS NOT REGISTERED ERROR</errmsg></db></dbs>')]);
        $this->artisan('venues:import-kopis')->expectsOutputToContain('SERVICE KEY IS NOT REGISTERED')->assertFailed();
        $this->assertSame(0, VenueCatalog::count());
    }

    public function test_basic_list_is_searchable_without_kopis(): void
    {
        config(['services.kopis.key' => null]);
        Http::fake();
        $this->seed(VenueCatalogSeeder::class);
        $this->seed(VenueCatalogSeeder::class);   // 다시 넣어도 중복 없음
        $this->assertSame(count(VenueCatalogSeeder::VENUES), VenueCatalog::count());

        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->getJson(route('admin.venues.catalog', ['q' => '고척']))->assertOk()
            ->assertJsonPath('0.name', '고척스카이돔')->assertJsonPath('0.source', 'seed');
        $this->getJson(route('admin.venues.catalog', ['q' => '부산']))->assertOk()->assertJsonCount(2);
        Http::assertNothingSent();
    }
}
