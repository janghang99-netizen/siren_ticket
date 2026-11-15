<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OrderService;
use App\Services\SettlementService;
use App\Services\VenueSetup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * 데모 데이터. 아티스트·업체는 전부 가상이다.
 *
 * 개발용 로그인 (비밀번호 모두 password)
 *   슈퍼어드민   admin@siren.test
 *   입점업체     arenafood@siren.test (매장 3) · beerhouse@siren.test (매장 2) · md@siren.test (매장 1)
 */
class DatabaseSeeder extends Seeder
{
    public function run(VenueSetup $venue): void
    {
        User::create(['role' => 'super_admin', 'name' => '운영사', 'email' => 'admin@siren.test', 'password' => 'password']);

        // ── 공연 일정 (슈퍼어드민이 등록하는 것과 같은 경로) ──
        $tiers = ['VIP' => ['price' => 176000, 'capacity' => 1500], 'R' => ['price' => 154000, 'capacity' => 2000], 'S' => ['price' => 132000, 'capacity' => 1200]];
        $schedule = [
            ['asteria-2026-seoul', 'ASTERIA WORLD TOUR 〈ORBIT〉 IN SEOUL', '아스테리아', 3, 18, '#6C4BFF', 4],
            ['asteria-2026-seoul-d2', 'ASTERIA WORLD TOUR 〈ORBIT〉 IN SEOUL · DAY 2', '아스테리아', 4, 17, '#6C4BFF', 4],
            ['nova9-fanmeeting', 'NOVA9 FANMEETING 〈SIGNAL〉', 'NOVA9', 10, 17, '#FF4F8B', 2],
        ];
        foreach ($schedule as $i => [$slug, $name, $artist, $days, $hour, $color, $max]) {
            $event = Event::create([
                'slug' => $slug, 'name' => $name, 'artist' => $artist, 'venue' => '서울아레나',
                'starts_at' => now()->addDays($days)->setTime($hour, 0), 'doors_at' => now()->addDays($days)->setTime($hour - 1, 30),
                'sales_open_at' => now()->subDay(), 'theme_color' => $color, 'max_per_buyer' => $max,
            ]);
            $venue->createSections($event, $tiers);

            // 판매 진행 상황 흉내 (첫 공연 F2 는 매진)
            mt_srand(42 + $i);
            foreach ($event->sections()->get() as $s) {
                $s->update(['sold' => ($i === 0 && $s->code === 'F2') ? $s->capacity : (int) ($s->capacity * mt_rand(20, 95) / 100)]);
            }
        }

        // ── 입점업체 → 매장 → 메뉴 (한 업체가 여러 매장) ──
        $vendors = [
            ['아레나푸드', 'arenafood', '123-45-67890', '김하늘', '국민은행', [
                ['arena1', '아레나 커피', [['아메리카노', 4500, ['HOT', 'ICE' => 500]], ['카페라떼', 5500, ['HOT', 'ICE' => 500]], ['레몬에이드', 6000, []]]],
                ['arena3', '오븐 베이커리', [['소금빵', 4000, []], ['크루아상', 4500, []], ['인터미션 세트', 9500, []]]],
                ['arena4', '드링크 스테이션', [['생수', 2000, []], ['이온음료', 3000, []], ['탄산음료', 3000, []]]],
            ]],
            ['비어하우스', 'beerhouse', '234-56-78901', '박준', '신한은행', [
                ['arena2', '비어 스탠드', [['생맥주 500', 9000, []], ['캔맥주', 7000, []], ['무알콜 맥주', 6000, []]]],
                ['arena5', '핫도그 키친', [['클래식 핫도그', 6500, []], ['치즈 핫도그', 7500, []], ['감자튀김', 5000, []]]],
            ]],
            ['공식 MD', 'md', '345-67-89012', '이서', '우리은행', [
                ['arena6', '공식 MD 숍', [['응원봉 배터리', 5000, []], ['포토카드 랜덤팩', 8000, []], ['슬로건 타월', 25000, []]]],
            ]],
        ];

        foreach ($vendors as [$vname, $login, $bizNo, $rep, $bank, $stores]) {
            $vendor = Vendor::create([
                'name' => $vname, 'business_no' => $bizNo, 'representative' => $rep,
                'contact_email' => "{$login}@siren.test", 'bank_name' => $bank,
                'bank_account' => '000-0000-0000-00', 'account_holder' => $vname, 'commission_rate' => 8.5,
            ]);
            User::create(['role' => 'vendor', 'vendor_id' => $vendor->id, 'name' => $vname, 'email' => "{$login}@siren.test", 'password' => 'password']);

            foreach ($stores as [$code, $sname, $menu]) {
                $store = Store::create(['vendor_id' => $vendor->id, 'code' => $code, 'name' => $sname, 'commission_rate' => 8.5]);
                foreach ($menu as $sort => [$pname, $price, $options]) {
                    $product = Product::create([
                        'store_id' => $store->id, 'name' => $pname, 'price' => $price, 'sort' => $sort,
                        'stock' => $options ? null : ($pname === '슬로건 타월' ? 0 : 300),   // 품절 표시 확인용 1개
                    ]);
                    foreach ($options as $key => $value) {
                        [$oname, $extra] = is_int($key) ? [$value, 0] : [$key, $value];
                        $product->options()->create(['name' => $oname, 'extra_price' => $extra, 'stock' => 300]);
                    }
                }
                $venue->ensureSlots(store: $store);
            }
        }

        $this->demoHistory();
    }

    /** 판매·취소·정산 화면이 비어 있지 않게: 지난주 수령완료 주문 → 정산서, 이번 주 주문 몇 건 + 취소 1건 */
    private function demoHistory(): void
    {
        $orders = app(OrderService::class);
        $order = function (string $code, array $lines, ?OrderStatus $to = null) use ($orders) {
            $store = Store::where('code', $code)->first();
            $items = collect($lines)->map(function ($qty, $name) use ($store) {
                $p = Product::where('store_id', $store->id)->where('name', $name)->first();

                return ['product_id' => $p->id, 'option_id' => $p->options()->value('id'), 'qty' => $qty];
            })->values()->all();

            $o = $orders->confirmPayment($orders->place($store, $items, 'card'));
            if ($to === OrderStatus::Cancelled) {
                return $orders->transition($o, OrderStatus::Cancelled);
            }
            foreach ([OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::PickedUp] as $step) {
                if ($to === null || $step->value > $to->value) {
                    break;
                }
                $o = $orders->transition($o, $step);
            }

            return $o;
        };

        // 지난주 — 수령완료 후 정산서 발행
        Carbon::setTestNow(now()->subWeek());
        $order('arena1', ['아메리카노' => 2, '카페라떼' => 1], OrderStatus::PickedUp);
        $order('arena3', ['소금빵' => 3], OrderStatus::PickedUp);
        $order('arena2', ['생맥주 500' => 2], OrderStatus::PickedUp);
        $order('arena5', ['치즈 핫도그' => 1, '감자튀김' => 1], OrderStatus::PickedUp);
        Carbon::setTestNow();
        app(SettlementService::class)->generate(now()->subWeeks(2), now()->subDays(6));

        // 이번 주 — 정산 대기 / 진행 중 / 취소
        $order('arena1', ['아메리카노' => 1], OrderStatus::PickedUp);
        $order('arena4', ['생수' => 2, '이온음료' => 1], OrderStatus::PickedUp);
        $order('arena1', ['레몬에이드' => 1]);
        $order('arena3', ['크루아상' => 2], OrderStatus::Preparing);
        $order('arena2', ['캔맥주' => 1], OrderStatus::Cancelled);
    }
}
