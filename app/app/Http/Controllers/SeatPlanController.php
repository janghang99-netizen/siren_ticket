<?php

namespace App\Http\Controllers;

use App\Models\SeatMap;
use App\Models\Venue;
use App\Services\Audit;
use App\Services\SeatMap\SeatPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** 슈퍼관리자 공연장 도면 편집기 — 무대·물체·글자와 구역 모양(사각·부채꼴·다각형), 구역마다 열·좌석 */
class SeatPlanController extends Controller
{
    public function __construct(private readonly SeatPlanService $plans) {}

    /** 공연장에 새 도면 (배치도 이미지 좌석표를 밑그림으로 고를 수 있다) */
    public function store(Request $request, Venue $venue): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'trace_id' => ['nullable', 'integer', 'exists:seat_maps,id'],
        ], ['name.required' => '도면 이름을 적어 주세요. (예: 체조경기장 · 엔드 무대)']);
        $trace = filled($data['trace_id'] ?? null) ? SeatMap::where('venue_id', $venue->id)->find($data['trace_id']) : null;
        $map = $this->plans->create($venue, $data['name'], $trace);
        Audit::log('seat_map.plan_created', $map, "도면 만들기 · {$map->name}");

        return redirect()->route('admin.seat-maps.plan', $map)->with('status', '도면을 만들었어요. 왼쪽 도구로 구역을 추가하고 저장하세요.');
    }

    public function edit(SeatMap $seatMap): View|RedirectResponse
    {
        if (! $seatMap->isPlan()) {
            return redirect()->route('admin.seat-maps.edit', $seatMap);
        }
        $seatMap->load('sections');

        return view('admin.seat-plan', [
            'map' => $seatMap,
            'sections' => $seatMap->sections->map(fn ($s) => [
                'code' => $s->code, 'tier' => $s->tier, 'shape' => $s->shape ?: $this->plans->shape([]),
                'rows' => $s->rows, 'per' => $s->seats_per_row, 'layout' => $s->layout,
                // 열마다 좌석 수가 다르면 "8,9,10" 글자로 (다각형은 모양에서 계산하므로 비운다)
                'counts' => $s->layout && ($s->shape['type'] ?? '') !== 'poly' && collect($s->layout)->map(fn ($r) => count($r['seats']))->unique()->count() > 1
                    ? collect($s->layout)->map(fn ($r) => count($r['seats']))->implode(',') : '',
            ])->values(),
            'usedBy' => $seatMap->events()->count(),
        ]);
    }

    public function update(Request $request, SeatMap $seatMap): JsonResponse
    {
        abort_unless($seatMap->isPlan(), 404);
        $payload = $request->validate([
            'tiers' => ['required', 'array', 'max:12'],
            'plan' => ['required', 'array'],
            'sections' => ['present', 'array', 'max:400'],
            'name' => ['nullable', 'string', 'max:80'],
        ]);
        $this->plans->save($seatMap, $payload);
        if (filled($payload['name'] ?? null)) {
            $seatMap->update(['name' => $payload['name']]);
        }
        Audit::log('seat_map.plan_saved', $seatMap, "도면 저장 · {$seatMap->name} · 구역 ".count($payload['sections']).'개');

        return response()->json(['ok' => true, 'seats' => $seatMap->fresh('sections')->totalSeats(), 'msg' => '저장했어요.']);
    }
}
