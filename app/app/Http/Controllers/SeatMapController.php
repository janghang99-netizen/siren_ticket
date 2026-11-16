<?php

namespace App\Http\Controllers;

use App\Models\SeatMap;
use App\Services\Audit;
use App\Services\SeatMap\SeatMapReader;
use App\Services\SeatMap\SeatMapReadFailed;
use App\Services\SeatMap\SeatMapService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 운영사 좌석표 — 기획사가 준 좌석 배치도 이미지를 올리면 AI 가 등급·구역 초안을 만들고,
 * 운영사가 이미지 위에서 구역 박스·열·좌석 수를 맞춰 확정한다. 공연 등록 때 좌석표를 고른다.
 */
class SeatMapController extends Controller
{
    public function __construct(private readonly SeatMapService $maps, private readonly SeatMapReader $reader) {}

    public function index(): View
    {
        return view('admin.seat-maps', [
            'maps' => SeatMap::with('sections')->withCount('events')->latest()->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.seat-map-create', ['aiReady' => $this->reader->ready()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'venue' => ['required', 'string', 'max:80'],
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'use_ai' => ['nullable', 'boolean'],
        ], [
            'image.required' => '좌석 배치도 이미지를 올려 주세요.', 'image.max' => '이미지는 10MB 까지 올릴 수 있어요.',
            'image.mimes' => 'JPG·PNG·WEBP 이미지만 올릴 수 있어요.',
        ]);

        $img = $this->maps->storeImage($request->file('image'));
        $map = SeatMap::create([
            'name' => $data['name'], 'venue' => $data['venue'], 'image_path' => $img['path'],
            'image_w' => $img['w'], 'image_h' => $img['h'],
            'tiers' => [['key' => 't1', 'name' => '일반', 'color' => '#6C4BFF', 'price' => 0, 'seats' => 0]],
        ]);
        Audit::log('seat_map.created', $map, "좌석표 등록 · {$map->name}");

        $status = '좌석표 이미지를 올렸어요. 이미지 위에서 구역을 끌어 그려 주세요.';
        if ($request->boolean('use_ai')) {
            $status = $this->readWithAi($map);
        }

        return redirect()->route('admin.seat-maps.edit', $map)->with('status', $status);
    }

    public function edit(SeatMap $seatMap): View
    {
        return view('admin.seat-map-edit', [
            'map' => $seatMap->load('sections')->loadCount('events'),
            'aiReady' => $this->reader->ready(),
        ]);
    }

    public function update(Request $request, SeatMap $seatMap): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'venue' => ['required', 'string', 'max:80'],
            'payload' => ['required', 'string', 'max:500000'],
        ]);
        $clean = $this->maps->normalize(json_decode($data['payload'], true));

        $seatMap->update(['name' => $data['name'], 'venue' => $data['venue']]);
        $this->maps->sync($seatMap, $clean['tiers'], $clean['sections']);
        Audit::log('seat_map.updated', $seatMap, "좌석표 저장 · {$seatMap->name} · 구역 ".count($clean['sections']).'개');

        $seats = array_sum(array_map(fn ($s) => $s['rows'] * $s['seats_per_row'], $clean['sections']));

        return redirect()->route('admin.seat-maps.edit', $seatMap)
            ->with('status', '좌석표를 저장했어요. 구역 '.count($clean['sections']).'개 · 좌석 '.number_format($seats).'석');
    }

    /** AI 로 다시 읽기 — 지금 구역을 판독 결과로 바꾼다 */
    public function reread(SeatMap $seatMap): RedirectResponse
    {
        return back()->with('status', $this->readWithAi($seatMap));
    }

    public function destroy(SeatMap $seatMap): RedirectResponse
    {
        if ($seatMap->events()->exists()) {
            return back()->withErrors(['payload' => '이 좌석표를 쓰는 공연이 있어 지울 수 없어요.']);
        }
        Audit::log('seat_map.deleted', $seatMap, "좌석표 삭제 · {$seatMap->name}");
        Storage::disk('local')->delete($seatMap->image_path);
        $seatMap->delete();

        return redirect()->route('admin.seat-maps.index')->with('status', '좌석표를 지웠어요.');
    }

    /** 좌석표 이미지 — 팬 예매 화면에서도 쓴다. 이름이 무작위 40자라 추측할 수 없고, 바꾸면 이름도 바뀐다. */
    public function image(string $file): BinaryFileResponse
    {
        $path = 'seat-maps/'.$file;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** AI 판독 → 초안 저장. 결과(성공·실패) 안내 문구를 돌려준다. */
    private function readWithAi(SeatMap $map): string
    {
        set_time_limit(240);   // 큰 배치도는 판독에 1~2분 걸릴 수 있다
        try {
            $read = $this->reader->read(Storage::disk('local')->get($map->image_path), $this->maps->mediaType($map));
        } catch (SeatMapReadFailed $e) {
            return 'AI 로 읽지 못했어요: '.$e->getMessage();
        }
        $draft = $this->maps->draft($read);
        $this->maps->sync($map, $draft['tiers'], $draft['sections']);
        $map->update(['read_by' => 'ai']);
        Audit::log('seat_map.read', $map, '좌석표 AI 판독 · 등급 '.count($draft['tiers']).'개 · 구역 '.count($draft['sections']).'개');

        return 'AI 가 등급 '.count($draft['tiers']).'개 · 구역 '.count($draft['sections']).'개를 읽었어요. 이미지와 겹쳐 보며 위치·좌석 수를 확인해 주세요.';
    }
}
