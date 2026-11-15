<?php

namespace App\Services;

use App\Models\CapacitySlot;
use App\Models\Event;
use App\Models\SeatSection;
use App\Models\Store;

/**
 * 공연장 구성: 보울 좌석 구역 생성 + 매장별 사이렌오더 시간대 캐파.
 * 슈퍼어드민이 공연을 등록하면 좌석 구역과 (모든 매장의) 시간대 캐파가 함께 만들어진다.
 */
class VenueSetup
{
    /** 플로어 3 + 1층 6 + 2층 8 구역. 무대 쪽(위) 60°는 비워 둔다. */
    public const LAYOUT = [
        0 => ['tier' => 'VIP', 'count' => 3, 'prefix' => 'F'],
        1 => ['tier' => 'R', 'count' => 6, 'prefix' => '1'],
        2 => ['tier' => 'S', 'count' => 8, 'prefix' => '2'],
    ];

    /** 설계문서 §9 초기 캐파 (매장당) + 마감 시각(공연 시작 기준 분) */
    public const SLOTS = [
        'pre' => ['capacity' => 540, 'closes_after' => 0],
        'intermission' => ['capacity' => 135, 'closes_after' => 95],
        'post' => ['capacity' => 180, 'closes_after' => 180],
    ];

    /**
     * @param  array<string, array{price:int, capacity:int}>  $tiers  등급별 가격·구역당 좌석 수 (VIP/R/S)
     */
    public function createSections(Event $event, array $tiers): void
    {
        foreach (self::LAYOUT as $ring => ['tier' => $tier, 'count' => $count, 'prefix' => $prefix]) {
            $span = 300 / $count;
            for ($k = 0; $k < $count; $k++) {
                $code = $ring === 0 ? 'F'.($k + 1) : $prefix.str_pad((string) ($k + 1), 2, '0', STR_PAD_LEFT);
                SeatSection::create([
                    'event_id' => $event->id, 'code' => $code, 'name' => $code.'구역', 'tier' => $tier,
                    'price' => (int) $tiers[$tier]['price'], 'capacity' => (int) $tiers[$tier]['capacity'],
                    'ring' => $ring,
                    'start_deg' => (int) round(-150 + $k * $span) + 1,
                    'end_deg' => (int) round(-150 + ($k + 1) * $span) - 1,
                ]);
            }
        }
    }

    /** 공연 × 매장 시간대 캐파를 없으면 만든다. 이미 있으면 건드리지 않는다(매장이 조정한 값 보존). */
    public function ensureSlots(?Event $event = null, ?Store $store = null): void
    {
        $events = $event ? collect([$event]) : Event::where('starts_at', '>', now())->get();
        $stores = $store ? collect([$store]) : Store::all();

        foreach ($events as $e) {
            foreach ($stores as $s) {
                foreach (self::SLOTS as $slot => ['capacity' => $cap, 'closes_after' => $after]) {
                    CapacitySlot::firstOrCreate(
                        ['event_id' => $e->id, 'store_id' => $s->id, 'slot' => $slot],
                        ['capacity' => $cap, 'closes_at' => $e->starts_at->copy()->addMinutes($after)],
                    );
                }
            }
        }
    }

    /** 공연 시작 시각이 바뀌면 마감 시각도 따라 옮긴다 */
    public function shiftSlotTimes(Event $event): void
    {
        foreach ($event->capacitySlots as $slot) {
            $after = self::SLOTS[$slot->slot]['closes_after'] ?? 0;
            $slot->update(['closes_at' => $event->starts_at->copy()->addMinutes($after)]);
        }
    }
}
