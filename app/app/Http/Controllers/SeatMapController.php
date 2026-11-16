<?php

namespace App\Http\Controllers;

use App\Models\SeatMap;
use App\Models\Venue;
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

    /** 좌석표 목록은 공연장 화면에서 공연장별로 본다 */
    public function index(): RedirectResponse
    {
        return redirect()->route('admin.venues.index');
    }

    public function create(Request $request): View
    {
        return view('admin.seat-map-create', [
            'aiReady' => $this->reader->ready(),
            'venues' => Venue::orderBy('name')->get(),
            'venueId' => (int) $request->query('venue'),
        ]);
    }

    private const IMAGE_RULES = ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'];

    private const IMAGE_MESSAGES = [
        'image.required' => '좌석 배치도 이미지를 올려 주세요.', 'image.max' => '이미지는 10MB 까지 올릴 수 있어요.',
        'image.mimes' => 'JPG·PNG·WEBP 이미지만 올릴 수 있어요.',
    ];

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'venue_id' => ['required', 'integer', 'exists:venues,id'],
            'name' => ['required', 'string', 'max:80'],
            'image' => self::IMAGE_RULES,
            'use_ai' => ['nullable', 'boolean'],
        ], self::IMAGE_MESSAGES + ['venue_id.required' => '공연장을 골라 주세요.']);
        $venue = Venue::findOrFail($data['venue_id']);

        $img = $this->maps->storeImage($request->file('image'));
        $map = SeatMap::create([
            'venue_id' => $venue->id, 'name' => $data['name'], 'venue' => $venue->name, 'image_path' => $img['path'],
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
            'map' => $seatMap->load(['sections', 'venueRecord'])->loadCount('events'),
            'aiReady' => $this->reader->ready(),
            'venues' => Venue::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, SeatMap $seatMap): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'venue_id' => ['required', 'integer', 'exists:venues,id'],
            'payload' => ['required', 'string', 'max:500000'],
        ]);
        $clean = $this->maps->normalize(json_decode($data['payload'], true));
        $venue = Venue::findOrFail($data['venue_id']);

        $seatMap->update(['name' => $data['name'], 'venue_id' => $venue->id, 'venue' => $venue->name]);
        $this->maps->sync($seatMap, $clean['tiers'], $clean['sections'], $clean['crop']);
        Audit::log('seat_map.updated', $seatMap, "좌석표 저장 · {$seatMap->name} · 구역 ".count($clean['sections']).'개');

        $seats = array_sum(array_map(fn ($s) => $s['rows'] * $s['seats_per_row'], $clean['sections']));

        $gap = $this->maps->seatGapNote($seatMap->fresh());

        return redirect()->route('admin.seat-maps.edit', $seatMap)
            ->with('status', '좌석표를 저장했어요. 구역 '.count($clean['sections']).'개 · 좌석 '.number_format($seats).'석'.($gap ? ' — '.$gap : ''));
    }

    /**
     * 배치도 이미지 다시 올리기 (기획사가 새 배치도를 준 경우). AI 를 쓸 수 있으면 등급·구역·보여줄 영역을 새로 만든다.
     * 이미 만든 공연은 공연에 복사한 구역을 쓰므로 영향이 없다 (공연 화면 이미지는 예전 파일을 계속 쓴다).
     */
    public function replaceImage(Request $request, SeatMap $seatMap): RedirectResponse
    {
        $request->validate(['image' => self::IMAGE_RULES, 'use_ai' => ['nullable', 'boolean']], self::IMAGE_MESSAGES);
        $img = $this->maps->storeImage($request->file('image'));
        $fresh = ['image_path' => $img['path'], 'image_w' => $img['w'], 'image_h' => $img['h'],
            'crop_x' => 0, 'crop_y' => 0, 'crop_w' => 1, 'crop_h' => 1, 'read_by' => 'manual'];

        // 공연이 이미 쓰는 좌석표는 그대로 두고(팬 화면 배치도·구역 위치 유지) 새 좌석표로 만든다
        $inUse = $seatMap->events()->exists();
        if ($inUse) {
            $target = $seatMap->replicate(['image_path', 'image_w', 'image_h'])->fill($fresh + ['name' => mb_substr($seatMap->name.' (새 배치도)', 0, 80)]);
            $target->save();
            $this->maps->sync($target, $seatMap->tiers, $seatMap->sections->map->only(['code', 'tier', 'x', 'y', 'w', 'h', 'rows', 'seats_per_row', 'layout'])->all());
        } else {
            Storage::disk('local')->delete($seatMap->image_path);
            $seatMap->update($fresh);
            $target = $seatMap;
        }
        Audit::log('seat_map.image', $target, "좌석표 이미지 교체 · {$target->name}");

        $status = $request->boolean('use_ai') && $this->reader->ready()
            ? $this->readWithAi($target)
            : '새 배치도를 올렸어요. 구역 박스 위치와 보여줄 영역을 새 이미지에 맞춰 주세요.';
        if ($inUse) {
            $status = '이 좌석표를 쓰는 공연이 있어 새 좌석표로 만들었어요. '.$status;
        }

        return redirect()->route('admin.seat-maps.edit', $target)->with('status', $status);
    }

    /** 좌석 리스트(CSV) 올리기 — 구역별 실제 좌석(열마다 다른 좌석 수·번호)으로 바꾼다 */
    public function importSeatList(Request $request, SeatMap $seatMap, \App\Services\SeatMap\SeatListImporter $importer): RedirectResponse
    {
        $request->validate(['seat_list' => ['required', 'file', 'max:5120', 'mimes:csv,txt']],
            ['seat_list.required' => '좌석 리스트 CSV 파일을 골라 주세요.', 'seat_list.mimes' => 'CSV 파일만 올릴 수 있어요. 엑셀에서 ‘CSV로 저장’ 해 주세요.']);
        $parsed = $importer->parse((string) file_get_contents($request->file('seat_list')->getRealPath()));
        $r = $importer->apply($seatMap, $parsed);
        Audit::log('seat_map.seat_list', $seatMap, "좌석 리스트 · {$seatMap->name} · ".number_format($r['seats']).'석');

        $msg = '좌석 리스트를 넣었어요: '.number_format($r['seats'])."석 · 구역 {$r['updated']}개 바꿈 · 새 구역 {$r['added']}개.";
        if ($r['added']) {
            $msg .= ' 새 구역은 배치도 왼쪽 위에 작게 놓았으니 끌어서 위치를 맞춰 주세요.';
        }
        if ($r['mixed']) {
            $msg .= ' 한 구역에 등급이 섞인 곳('.implode(', ', array_slice($r['mixed'], 0, 5)).')은 많은 쪽 등급으로 넣었어요.';
        }
        if ($gap = $this->maps->seatGapNote($seatMap->fresh())) {
            $msg .= ' '.$gap;
        }

        return redirect()->route('admin.seat-maps.edit', $seatMap)->with('status', $msg);
    }

    public function seatListSample(): \Symfony\Component\HttpFoundation\Response
    {
        return response(\App\Services\SeatMap\SeatListImporter::sample(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="seat-list-sample.csv"',
        ]);
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
        $this->maps->sync($map, $draft['tiers'], $draft['sections'], $draft['crop']);
        $map->update(['read_by' => 'ai']);
        Audit::log('seat_map.read', $map, '좌석표 AI 판독 · 등급 '.count($draft['tiers']).'개 · 구역 '.count($draft['sections']).'개');

        return 'AI 가 등급 '.count($draft['tiers']).'개 · 구역 '.count($draft['sections']).'개를 읽었어요. 이미지와 겹쳐 보며 위치·좌석 수를 확인해 주세요.';
    }
}
