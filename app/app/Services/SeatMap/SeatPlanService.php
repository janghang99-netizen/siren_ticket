<?php

namespace App\Services\SeatMap;

use App\Models\SeatMap;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 공연장 도면 (편집기로 그린 좌석표, seat_maps.kind = plan).
 * 1000×1000 캔버스에 무대·물체·글자와 구역 모양(사각 · 부채꼴 · 다각형)을 두고, 구역마다 열·좌석 수를 정한다.
 * 좌석 위치는 resources/js/seatplan.js 가 모양에서 계산한다 (편집기 미리보기 = 팬 좌석 화면).
 */
class SeatPlanService
{
    public const SIZE = 1000;

    public const DEFAULT_TIERS = [
        ['key' => 'VIP', 'name' => 'VIP', 'color' => '#8B5CF6', 'price' => 150000, 'seats' => 0],
        ['key' => 'R', 'name' => 'R', 'color' => '#3B82F6', 'price' => 120000, 'seats' => 0],
        ['key' => 'S', 'name' => 'S', 'color' => '#10B981', 'price' => 90000, 'seats' => 0],
    ];

    /** 새 도면 — 무대만 있는 빈 캔버스. $trace 가 있으면 그 배치도 이미지를 밑그림으로 복사해 둔다 */
    public function create(Venue $venue, string $name, ?SeatMap $trace = null): SeatMap
    {
        $image = [];
        if ($trace?->image_path && Storage::disk('local')->exists($trace->image_path)) {
            $copy = 'seat-maps/'.Str::random(40).'.'.pathinfo($trace->image_path, PATHINFO_EXTENSION);
            Storage::disk('local')->copy($trace->image_path, $copy);
            $image = ['image_path' => $copy, 'image_w' => $trace->image_w, 'image_h' => $trace->image_h];
        }

        return SeatMap::create($image + [
            'kind' => 'plan', 'venue_id' => $venue->id, 'venue' => $venue->name, 'name' => mb_substr($name, 0, 80),
            'tiers' => self::DEFAULT_TIERS,
            'plan' => ['stage' => ['x' => 300, 'y' => 40, 'w' => 400, 'h' => 90, 'rot' => 0, 'label' => 'STAGE'], 'objects' => [], 'labels' => []],
        ]);
    }

    /**
     * 편집기 저장 — 등급·무대·물체·글자·구역을 통째로 바꾼다 (이미 만든 공연에는 영향 없음: 공연은 구역을 복사해 둔다).
     *
     * @param  array{tiers?:array, plan?:array, sections?:array}  $payload
     */
    public function save(SeatMap $map, array $payload): void
    {
        $tiers = $this->tiers($payload['tiers'] ?? []);
        $keys = array_column($tiers, 'key');
        $plan = $this->plan($payload['plan'] ?? []);
        $sections = [];
        $codes = [];
        foreach (array_values($payload['sections'] ?? []) as $i => $s) {
            $code = mb_strtoupper(mb_substr(trim((string) ($s['code'] ?? '')), 0, 10));
            if ($code === '') {
                throw ValidationException::withMessages(['sections' => ($i + 1).'번째 구역의 이름을 적어 주세요.']);
            }
            if (isset($codes[$code])) {
                throw ValidationException::withMessages(['sections' => "구역 이름 ‘{$code}’가 두 번 있어요. 구역 이름은 겹치면 안 돼요."]);
            }
            $codes[$code] = true;
            $tier = in_array($s['tier'] ?? null, $keys, true) ? $s['tier'] : $keys[0];
            $shape = $this->shape($s['shape'] ?? []);
            $layout = $this->layout($s['counts'] ?? null, $shape['rowLabels']);
            $rows = $layout ? count($layout) : $this->int($s['rows'] ?? 0, 1, 200);
            $per = $layout ? max(array_map(fn ($r) => count($r['seats']), $layout)) : $this->int($s['per'] ?? 0, 1, 300);
            if (! $layout && $shape['rowLabels'] === 'alpha') {
                $layout = $this->layout(implode(',', array_fill(0, $rows, $per)), 'alpha');   // 글자 열 이름은 layout 에 둔다
            }
            $sections[] = ['code' => $code, 'tier' => $tier, 'shape' => $shape, 'rows' => $rows,
                'seats_per_row' => $per, 'layout' => $layout, 'sort' => $i];
        }

        DB::transaction(function () use ($map, $tiers, $plan, $sections) {
            $map->update(['tiers' => $tiers, 'plan' => $plan]);
            $map->sections()->delete();
            foreach ($sections as $s) {
                $map->sections()->create($s);
            }
            // 등급별 좌석 수 (목록·공연 등록 화면 표시용)
            $map->load('sections');
            $map->update(['tiers' => array_map(fn ($t) => ['seats' => (int) $map->sections->where('tier', $t['key'])->sum(fn ($s) => $s->capacity())] + $t, $tiers)]);
        });
    }

    private function tiers(array $in): array
    {
        $out = [];
        foreach (array_slice($in, 0, 12) as $t) {
            $key = mb_substr(preg_replace('/[^\p{L}\p{N}_-]/u', '', (string) ($t['key'] ?? $t['name'] ?? '')), 0, 10);
            $name = mb_substr(trim((string) ($t['name'] ?? $key)), 0, 10);
            if ($key === '' || isset($out[$key])) {
                continue;
            }
            $out[$key] = ['key' => $key, 'name' => $name ?: $key, 'color' => $this->color($t['color'] ?? ''),
                'price' => $this->int($t['price'] ?? 0, 0, 10_000_000), 'seats' => 0];
        }
        if (! $out) {
            throw ValidationException::withMessages(['tiers' => '좌석 등급을 하나 이상 만들어 주세요.']);
        }

        return array_values($out);
    }

    private function plan(array $p): array
    {
        $box = fn ($o, $label) => [
            'x' => $this->num($o['x'] ?? 0, -200, 1200), 'y' => $this->num($o['y'] ?? 0, -200, 1200),
            'w' => $this->num($o['w'] ?? 100, 5, 1200), 'h' => $this->num($o['h'] ?? 40, 5, 1200),
            'rot' => $this->num($o['rot'] ?? 0, -180, 180), 'label' => mb_substr(trim((string) ($o['label'] ?? $label)), 0, 20),
        ];

        return [
            'stage' => $box($p['stage'] ?? [], 'STAGE'),
            'objects' => array_map(fn ($o) => $box($o, ''), array_slice(array_values($p['objects'] ?? []), 0, 30)),
            'labels' => array_values(array_filter(array_map(fn ($l) => [
                'x' => $this->num($l['x'] ?? 0, -200, 1200), 'y' => $this->num($l['y'] ?? 0, -200, 1200),
                'text' => mb_substr(trim((string) ($l['text'] ?? '')), 0, 20), 'size' => $this->num($l['size'] ?? 24, 8, 120),
            ], array_slice(array_values($p['labels'] ?? []), 0, 40)), fn ($l) => $l['text'] !== '')),
        ];
    }

    /** 구역 모양 정리 (사각 · 부채꼴 · 다각형) — seatplan.js 와 같은 필드 */
    public function shape(array $s): array
    {
        $common = [
            'align' => in_array($s['align'] ?? '', ['left', 'right'], true) ? $s['align'] : 'center',
            'rowLabels' => ($s['rowLabels'] ?? '') === 'alpha' ? 'alpha' : 'num',
        ];
        $type = $s['type'] ?? 'rect';
        if ($type === 'arc') {
            $r0 = $this->num($s['r0'] ?? 100, 0, 1400);
            $a0 = $this->num($s['a0'] ?? 0, -720, 720);

            return ['type' => 'arc', 'cx' => $this->num($s['cx'] ?? 500, -500, 1500), 'cy' => $this->num($s['cy'] ?? 500, -500, 1500),
                'r0' => $r0, 'r1' => max($r0 + 4, $this->num($s['r1'] ?? $r0 + 60, 0, 1500)),
                'a0' => $a0, 'a1' => max($a0 + 2, min($a0 + 360, $this->num($s['a1'] ?? $a0 + 40, -720, 1080))),
                'row1' => ($s['row1'] ?? '') === 'outer' ? 'outer' : 'inner',
                'dir' => ($s['dir'] ?? '') === 'ccw' ? 'ccw' : 'cw'] + $common;
        }
        if ($type === 'poly') {
            $pts = array_values(array_filter(array_map(fn ($p) => is_array($p) && count($p) >= 2
                ? [$this->num($p[0], -200, 1200), $this->num($p[1], -200, 1200)] : null, array_slice($s['points'] ?? [], 0, 40))));
            if (count($pts) < 3) {
                throw ValidationException::withMessages(['sections' => '다각형 구역은 점이 3개 이상 있어야 해요.']);
            }

            // 격자(gr 열 × gc 칸)를 구역 방향(rot)대로 깔고 모양 밖 칸은 뺀다 — 열별 좌석 수는 편집기가 계산해 counts 로 보낸다
            return ['type' => 'poly', 'points' => $pts, 'rot' => $this->num($s['rot'] ?? 0, -180, 180),
                'gr' => $this->int($s['gr'] ?? 10, 1, 200), 'gc' => $this->int($s['gc'] ?? 20, 1, 300),
                'row1' => ($s['row1'] ?? '') === 'bottom' ? 'bottom' : 'top',
                'dir' => ($s['dir'] ?? '') === 'rtl' ? 'rtl' : 'ltr'] + $common;
        }

        return ['type' => 'rect', 'x' => $this->num($s['x'] ?? 0, -200, 1200), 'y' => $this->num($s['y'] ?? 0, -200, 1200),
            'w' => $this->num($s['w'] ?? 120, 5, 1200), 'h' => $this->num($s['h'] ?? 80, 5, 1200),
            'rot' => $this->num($s['rot'] ?? 0, -180, 180), 'curve' => $this->num($s['curve'] ?? 0, -1, 1),
            'row1' => ($s['row1'] ?? '') === 'bottom' ? 'bottom' : 'top',
            'dir' => ($s['dir'] ?? '') === 'rtl' ? 'rtl' : 'ltr'] + $common;
    }

    /** "8,9,10,12" (1열부터 열마다 좌석 수) → layout. 비었으면 null (열 × 열당 좌석 격자) */
    public function layout(mixed $counts, string $rowLabels = 'num'): ?array
    {
        $nums = array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', trim((string) $counts)) ?: []), fn ($n) => $n > 0));
        if (! $nums) {
            return null;
        }
        if (count($nums) > 200 || max($nums) > 300) {
            throw ValidationException::withMessages(['sections' => '열은 200개, 한 열은 300석까지 만들 수 있어요.']);
        }

        return array_map(fn ($c, $i) => ['n' => $i + 1, 'label' => $rowLabels === 'alpha' ? self::alpha($i) : (string) ($i + 1), 'seats' => range(1, $c)],
            $nums, array_keys($nums));
    }

    /** 0 → A, 25 → Z, 26 → AA */
    public static function alpha(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26).$s;
        }

        return $s;
    }

    private function num(mixed $v, float $min, float $max): float
    {
        return round(max($min, min($max, (float) $v)), 2);
    }

    private function int(mixed $v, int $min, int $max): int
    {
        return max($min, min($max, (int) $v));
    }

    private function color(mixed $c): string
    {
        $c = strtoupper(trim((string) $c));

        return preg_match('/^#?([0-9A-F]{6})$/', $c, $m) ? '#'.$m[1] : '#6C4BFF';
    }
}
