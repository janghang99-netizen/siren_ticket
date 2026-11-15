<?php

namespace Database\Seeders;

use App\Models\CapacitySlot;
use App\Models\Event;
use App\Models\Product;
use App\Models\SeatSection;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * 데모 데이터. 아티스트는 전부 가상의 그룹이다.
 * 매장 직원 테스트 계정: arena1@siren.test ~ arena6@siren.test / 비밀번호 password
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $events = [
            Event::create([
                'slug' => 'asteria-2026-seoul', 'name' => 'ASTERIA WORLD TOUR 〈ORBIT〉 IN SEOUL', 'artist' => '아스테리아',
                'venue' => '서울아레나', 'starts_at' => now()->addDays(3)->setTime(18, 0), 'doors_at' => now()->addDays(3)->setTime(16, 30),
                'sales_open_at' => now()->subDay(), 'theme_color' => '#6C4BFF', 'max_per_buyer' => 4,
            ]),
            Event::create([
                'slug' => 'nova9-fanmeeting', 'name' => 'NOVA9 FANMEETING 〈SIGNAL〉', 'artist' => 'NOVA9',
                'venue' => '서울아레나', 'starts_at' => now()->addDays(10)->setTime(17, 0), 'doors_at' => now()->addDays(10)->setTime(15, 30),
                'sales_open_at' => now()->subHours(2), 'theme_color' => '#FF4F8B', 'max_per_buyer' => 2,
            ]),
        ];

        foreach ($events as $i => $event) {
            $this->sections($event, $i);
        }

        $stores = [
            ['아레나 커피', [['아메리카노', 4500, ['HOT', 'ICE' => 500]], ['카페라떼', 5500, ['HOT', 'ICE' => 500]], ['레몬에이드', 6000, []]]],
            ['비어 스탠드', [['생맥주 500', 9000, []], ['캔맥주', 7000, []], ['무알콜 맥주', 6000, []]]],
            ['오븐 베이커리', [['소금빵', 4000, []], ['크루아상', 4500, []], ['인터미션 세트', 9500, []]]],
            ['드링크 스테이션', [['생수', 2000, []], ['이온음료', 3000, []], ['탄산음료', 3000, []]]],
            ['핫도그 키친', [['클래식 핫도그', 6500, []], ['치즈 핫도그', 7500, []], ['감자튀김', 5000, []]]],
            ['공식 MD 숍', [['응원봉 배터리', 5000, []], ['포토카드 랜덤팩', 8000, []], ['슬로건 타월', 25000, []]]],
        ];

        foreach ($stores as $n => [$name, $menu]) {
            $store = Store::create(['code' => 'arena'.($n + 1), 'name' => $name, 'commission_rate' => 8.5]);
            User::create([
                'store_id' => $store->id, 'name' => $name.' 직원',
                'email' => 'arena'.($n + 1).'@siren.test', 'password' => Hash::make('password'),
            ]);

            foreach ($menu as $sort => [$pname, $price, $options]) {
                $product = Product::create([
                    'store_id' => $store->id, 'name' => $pname, 'price' => $price, 'sort' => $sort,
                    'stock' => $options ? null : ($pname === '슬로건 타월' ? 0 : 300),   // 품절 표시 확인용 1개
                    'stock_deduct_at' => 'order',
                ]);
                foreach ($options as $key => $value) {
                    [$oname, $extra] = is_int($key) ? [$value, 0] : [$key, $value];
                    $product->options()->create(['name' => $oname, 'extra_price' => $extra, 'stock' => 300]);
                }
            }

            // 설계문서 §9 초기 캐파 (매장당): 공연 전 540 / 인터미션 135 / 공연 후 180
            foreach ($events as $event) {
                foreach ([['pre', 540, 0], ['intermission', 135, 95], ['post', 180, 180]] as [$slot, $cap, $closeAfter]) {
                    CapacitySlot::create([
                        'event_id' => $event->id, 'store_id' => $store->id, 'slot' => $slot, 'capacity' => $cap,
                        'closes_at' => $event->starts_at->copy()->addMinutes($closeAfter),
                    ]);
                }
            }
        }
    }

    /** 플로어 3 + 1층 6 + 2층 8 구역. 무대 쪽(위) 60°는 비워 둔다. */
    private function sections(Event $event, int $seed): void
    {
        mt_srand(42 + $seed);
        $rings = [
            0 => ['F', 3, 'VIP', 176000, 1500],
            1 => ['1', 6, 'R', 154000, 2000],
            2 => ['2', 8, 'S', 132000, 1200],
        ];

        foreach ($rings as $ring => [$prefix, $count, $tier, $price, $cap]) {
            $span = 300 / $count;
            for ($k = 0; $k < $count; $k++) {
                $code = $ring === 0 ? 'F'.($k + 1) : $prefix.str_pad((string) ($k + 1), 2, '0', STR_PAD_LEFT);
                $sold = ($ring === 0 && $k === 1) ? $cap : (int) ($cap * mt_rand(20, 95) / 100);  // F2 는 매진
                SeatSection::create([
                    'event_id' => $event->id, 'code' => $code, 'name' => $code.'구역', 'tier' => $tier,
                    'price' => $price, 'capacity' => $cap, 'sold' => $sold, 'ring' => $ring,
                    'start_deg' => (int) round(-150 + $k * $span) + 1,
                    'end_deg' => (int) round(-150 + ($k + 1) * $span) - 1,
                ]);
            }
        }
    }
}
