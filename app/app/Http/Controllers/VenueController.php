<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\SeatMap;
use App\Models\Venue;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 운영사 공연장 목록 — 공연장마다 무대 배치별 좌석표(배치도 이미지 → 구역·좌석)를 두고,
 * 공연을 등록할 때 공연장과 좌석표를 고른다.
 */
class VenueController extends Controller
{
    public function index(): View
    {
        return view('admin.venues', [
            'venues' => Venue::with(['seatMaps.sections'])->withCount('events')
                ->withMin(['events as next_event_at' => fn ($q) => $q->where('starts_at', '>', now())], 'starts_at')
                ->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $venue = Venue::create($data);
        Audit::log('venue.created', $venue, "공연장 등록 · {$venue->name}");

        return redirect()->route('admin.venues.show', $venue)->with('status', "‘{$venue->name}’ 공연장을 만들었어요. 좌석 배치도 이미지를 올려 좌석표를 만드세요.");
    }

    public function show(Venue $venue): View
    {
        return view('admin.venue', [
            'venue' => $venue,
            'maps' => SeatMap::where('venue_id', $venue->id)->with('sections')->withCount('events')->latest('id')->get(),
            'events' => Event::where('venue_id', $venue->id)->with('seatMap')->orderByDesc('starts_at')->limit(20)->get(),
        ]);
    }

    public function update(Request $request, Venue $venue): RedirectResponse
    {
        $data = $this->validated($request, $venue);
        $venue->update($data);
        // 화면에 보이는 공연장 이름도 같이 바꾼다
        Event::where('venue_id', $venue->id)->update(['venue' => $venue->name]);
        SeatMap::where('venue_id', $venue->id)->update(['venue' => $venue->name]);
        Audit::log('venue.updated', $venue, "공연장 수정 · {$venue->name}");

        return back()->with('status', '공연장 정보를 저장했어요.');
    }

    public function destroy(Venue $venue): RedirectResponse
    {
        if (Event::where('venue_id', $venue->id)->exists() || SeatMap::where('venue_id', $venue->id)->exists()) {
            return back()->withErrors(['name' => '공연이나 좌석표가 있는 공연장은 지울 수 없어요. 좌석표부터 지워 주세요.']);
        }
        Audit::log('venue.deleted', $venue, "공연장 삭제 · {$venue->name}");
        $venue->delete();

        return redirect()->route('admin.venues.index')->with('status', '공연장을 지웠어요.');
    }

    private function validated(Request $request, ?Venue $venue = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('venues', 'name')->ignore($venue?->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['name.required' => '공연장 이름을 적어 주세요.', 'name.unique' => '이미 있는 공연장이에요.']);
    }
}
