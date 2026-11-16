<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\SeatSection;
use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * 공연별 좌석 막기 — 판매 제외(카메라·콘솔·시야 방해) / 보류(초대석·기획사 몫).
 * 막은 좌석은 팬 화면에서 '판매됨'으로 보이고 팔리지 않는다. 보류석은 풀면 다시 판매된다.
 */
class EventSeatController extends Controller
{
    public const KINDS = ['kill' => '판매 제외', 'hold' => '보류'];

    public function show(Event $event): View
    {
        $sections = $event->sections()->get()->filter->hasSeatGrid()->values();

        return view('admin.event-seats', [
            'event' => $event->load('seatMap'),
            'sections' => $sections,
            'counts' => DB::table('seat_blocks')->where('event_id', $event->id)->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind'),
        ]);
    }

    /** 구역 좌석 현황 (운영사용 — 팔린 좌석·막은 좌석을 구분해서) */
    public function section(Event $event, int $section): JsonResponse
    {
        $s = SeatSection::where('event_id', $event->id)->findOrFail($section);
        abort_unless($s->hasSeatGrid(), 404);

        return response()->json([
            'rows' => $s->rows, 'per_row' => $s->seats_per_row, 'layout' => $s->layout,
            'sold' => DB::table('taken_seats')->where('seat_section_id', $s->id)->get(['row_no', 'seat_no'])->map(fn ($t) => $t->row_no.'-'.$t->seat_no),
            'blocks' => DB::table('seat_blocks')->where('seat_section_id', $s->id)->get(['row_no', 'seat_no', 'kind', 'note'])
                ->mapWithKeys(fn ($b) => [$b->row_no.'-'.$b->seat_no => ['kind' => $b->kind, 'note' => $b->note]]),
            'capacity' => $s->capacity, 'sold_count' => $s->sold, 'blocked' => $s->blocked,
        ])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        $data = $request->validate([
            'section_id' => ['required', 'integer'],
            'action' => ['required', 'in:kill,hold,release'],
            'seats' => ['required', 'array', 'min:1', 'max:2000'],
            'seats.*' => ['string', 'regex:/^\d{1,3}-\d{1,3}$/'],
            'note' => ['nullable', 'string', 'max:60'],
        ], ['seats.required' => '좌석을 먼저 골라 주세요.']);

        $result = DB::transaction(function () use ($data, $event, $request) {
            // 구역 행을 잠가 같은 구역 예매와 겹치지 않게 한다 (예매도 이 행을 잠근다)
            $section = SeatSection::where('event_id', $event->id)->lockForUpdate()->findOrFail($data['section_id']);
            abort_unless($section->hasSeatGrid(), 422, '좌석을 고를 수 없는 구역이에요.');

            $seats = collect($data['seats'])->unique()->map(fn ($k) => array_map('intval', explode('-', $k)))
                ->filter(fn ($p) => $section->hasSeat($p[0], $p[1]))->values();
            $sold = DB::table('taken_seats')->where('seat_section_id', $section->id)->get(['row_no', 'seat_no'])
                ->map(fn ($t) => $t->row_no.'-'.$t->seat_no)->flip();
            [$done, $skipped] = [0, 0];

            foreach ($seats as [$row, $no]) {
                $where = ['seat_section_id' => $section->id, 'row_no' => $row, 'seat_no' => $no];
                if ($data['action'] === 'release') {
                    $done += DB::table('seat_blocks')->where($where)->delete();

                    continue;
                }
                if ($sold->has("{$row}-{$no}")) {   // 이미 팔린 좌석은 막지 않는다 (예매 취소 후 막기)
                    $skipped++;

                    continue;
                }
                DB::table('seat_blocks')->updateOrInsert($where, [
                    'event_id' => $event->id, 'kind' => $data['action'], 'note' => $data['note'] ?? null,
                    'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $done++;
            }
            $section->update(['blocked' => DB::table('seat_blocks')->where('seat_section_id', $section->id)->count()]);

            return [$section, $done, $skipped];
        });
        [$section, $done, $skipped] = $result;

        $label = $data['action'] === 'release' ? '풀었어요' : self::KINDS[$data['action']].'로 막았어요';
        Audit::log('event.seat_block', $event, "{$section->code}구역 좌석 {$done}석 {$label}", ['action' => $data['action'], 'seats' => $data['seats']]);

        return redirect()->route('admin.events.seats', [$event->id, 'section' => $section->id])
            ->with('status', "{$section->code}구역 {$done}석을 {$label}.".($skipped ? " 이미 팔린 {$skipped}석은 건너뛰었어요." : ''));
    }
}
