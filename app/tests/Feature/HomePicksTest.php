<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Product;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 홈 (공연 기능 끔 = 다담다 상시 매장 주문) */
class HomePicksTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    /** '지금 주문할 수 있는 메뉴': 영업 중 매장의 판매 중 상품을, 최근 30일 실제로 많이 팔린 순으로 */
    public function test_home_picks_best_sellers_from_open_stores(): void
    {
        \App\Models\Setting::put(['ticketing_enabled' => '0']);
        $vendor = Vendor::create(['name' => '빵집', 'state' => 'approved']);
        $open = Store::create(['vendor_id' => $vendor->id, 'code' => 'bread', 'name' => '도봉빵', 'is_open' => true]);
        $closed = Store::create(['vendor_id' => $vendor->id, 'code' => 'cafe', 'name' => '닫은카페', 'is_open' => false]);

        Product::create(['store_id' => $open->id, 'name' => '안 팔린 식빵', 'price' => 3000]);
        $best = Product::create(['store_id' => $open->id, 'name' => '잘 팔린 소금빵', 'price' => 2800]);
        $old = Product::create(['store_id' => $open->id, 'name' => '오래전 바게트', 'price' => 4000]);
        $refunded = Product::create(['store_id' => $open->id, 'name' => '환불된 치아바타', 'price' => 4300]);
        Product::create(['store_id' => $open->id, 'name' => '판매중지 크로와상', 'price' => 3500, 'is_visible' => false]);
        Product::create(['store_id' => $closed->id, 'name' => '닫은 매장 라떼', 'price' => 5000]);

        $this->sell($best, 9, 6, now()->subDays(2));     // 정산완료 9개
        $this->sell($old, 50, 6, now()->subDays(40));    // 30일 지난 판매는 세지 않음
        $this->sell($refunded, 50, 14, now()->subDay()); // 환불은 세지 않음

        $html = $this->get('/')->assertOk()->getContent();
        $section = substr($html, strpos($html, '지금 주문할 수 있는 메뉴'));

        // 실제로 팔린 소금빵이 맨 앞 (30일 지난 판매·환불은 세지 않아 바게트·치아바타는 0개 취급)
        foreach (['안 팔린 식빵', '오래전 바게트', '환불된 치아바타'] as $other) {
            $this->assertLessThan(strpos($section, $other), strpos($section, '잘 팔린 소금빵'));
        }
        $this->assertStringNotContainsString('판매중지 크로와상', $section);
        $this->assertStringNotContainsString('닫은 매장 라떼', $section);
    }

    /** 공연 기능을 끄면 홈에 공연 목록(공연 일정)이 나오지 않는다 */
    public function test_home_hides_events_when_ticketing_is_off(): void
    {
        \App\Models\Setting::put(['ticketing_enabled' => '0']);
        Event::create(['slug' => 'live-1', 'name' => '테스트 라이브', 'artist' => '테스트밴드', 'venue' => '공연장',
            'starts_at' => now()->addDays(3), 'status' => 'scheduled']);

        $this->get('/')->assertOk()->assertDontSee('공연 일정')->assertDontSee('테스트밴드');
    }

    private function sell(Product $p, int $qty, int $status, $at): void
    {
        $this->n++;
        $id = DB::table('orders')->insertGetId([
            'order_no' => 'T'.$this->n, 'pickup_code' => str_pad((string) $this->n, 4, '0', STR_PAD_LEFT), 'store_id' => $p->store_id,
            'status' => $status, 'pay_method' => 'card', 'total_amount' => $p->price * $qty, 'commission_rate' => 8.5,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $id, 'product_id' => $p->id, 'name' => $p->name, 'unit_price' => $p->price, 'qty' => $qty,
            'line_total' => $p->price * $qty, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }
}
