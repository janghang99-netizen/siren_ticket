<?php

namespace App\Services\SeatMap;

use App\Models\SeatMap;
use App\Models\SeatMapSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 기획사·공연장 좌석 리스트(CSV) → 구역별 실제 좌석 (열마다 다른 좌석 수·번호 그대로).
 *
 * 열: 구역, 열, 번호, 등급(선택), 가격(선택) — 첫 줄이 머리글이면 이름으로 찾고, 없으면 이 순서로 읽는다.
 *  - 번호는 한 칸에 하나(12) 또는 범위(1-18, 1~18)
 *  - "A구역", "3열", "15번" 처럼 글자가 붙어 있어도 된다
 *  - 엑셀 기본 CSV(EUC-KR)와 UTF-8 모두 읽는다
 */
class SeatListImporter
{
    public const MAX_SEATS = 120000;

    private const HEADERS = [
        'section' => ['구역', 'section', 'block', 'zone'],
        'row' => ['열', 'row', '줄'],
        'seat' => ['번호', '번', '좌석', '좌석번호', 'seat', 'no', 'number'],
        'grade' => ['등급', '좌석등급', 'grade', 'tier', 'class'],
        'price' => ['가격', '금액', 'price'],
    ];

    private const COLORS = ['#1E2A78', '#F04A4A', '#22C55E', '#FACC15', '#29D3F5', '#FF6A1F', '#8B3FE0', '#EC4899', '#14B8A6', '#64748B'];

    /**
     * @return array{sections: array<string, array{code:string, grade:?string, grades:array, price:?int, rows: array<string, list<int>>}>, seats:int}
     */
    public function parse(string $bytes): array
    {
        $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            $bytes = mb_convert_encoding($bytes, 'UTF-8', 'CP949');   // 엑셀 한글 CSV
        }
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $bytes);
        rewind($fh);

        $cols = ['section' => 0, 'row' => 1, 'seat' => 2, 'grade' => 3, 'price' => 4];
        $sections = [];
        $seats = 0;
        $errors = [];
        $line = 0;
        while (($cells = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $line++;
            $cells = array_map(fn ($c) => trim((string) $c), $cells);
            if (count(array_filter($cells, fn ($c) => $c !== '')) === 0) {
                continue;
            }
            if ($line === 1 && $this->isHeader($cells)) {
                $cols = $this->columns($cells);

                continue;
            }
            $code = preg_replace('/\s*구역$/u', '', $cells[$cols['section']] ?? '');
            $row = preg_replace('/\s*열$/u', '', $cells[$cols['row']] ?? '');
            $nums = $this->numbers($cells[$cols['seat']] ?? '');
            if (! preg_match('/^[\p{L}\p{N}\-_.]{1,10}$/u', $code) || ! preg_match('/^[\p{L}\p{N}\-]{1,10}$/u', $row) || $nums === null) {
                if (count($errors) < 5) {
                    $errors[] = "{$line}번째 줄 (".implode(', ', array_slice($cells, 0, 3)).')';
                }

                continue;
            }
            $key = mb_strtoupper($code);
            $sections[$key] ??= ['code' => $code, 'grade' => null, 'grades' => [], 'price' => null, 'rows' => []];
            $grade = isset($cols['grade']) ? mb_substr($cells[$cols['grade']] ?? '', 0, 30) : '';
            if ($grade !== '') {
                $sections[$key]['grades'][$grade] = ($sections[$key]['grades'][$grade] ?? 0) + count($nums);
            }
            $price = isset($cols['price']) ? preg_replace('/\D/', '', $cells[$cols['price']] ?? '') : '';
            if ($price !== '') {
                $sections[$key]['price'] = (int) $price;
            }
            $before = count($sections[$key]['rows'][$row] ?? []);
            $sections[$key]['rows'][$row] = array_values(array_unique([...($sections[$key]['rows'][$row] ?? []), ...$nums]));
            $seats += count($sections[$key]['rows'][$row]) - $before;
            if ($seats > self::MAX_SEATS) {
                throw ValidationException::withMessages(['seat_list' => '좌석이 너무 많아요 ('.number_format(self::MAX_SEATS).'석까지).']);
            }
        }
        fclose($fh);

        if ($errors) {
            throw ValidationException::withMessages(['seat_list' => '읽지 못한 줄이 있어요: '.implode(' · ', $errors).'. 구역·열·번호를 확인해 주세요.']);
        }
        if ($seats === 0) {
            throw ValidationException::withMessages(['seat_list' => '좌석이 없어요. 구역, 열, 번호 순서의 CSV 인지 확인해 주세요.']);
        }
        foreach ($sections as &$s) {
            arsort($s['grades']);
            $s['grade'] = array_key_first($s['grades']);   // 구역 안 등급이 섞여 있으면 많은 쪽
            uksort($s['rows'], 'strnatcasecmp');
            foreach ($s['rows'] as &$nums) {
                sort($nums);
            }
            unset($nums);
        }
        unset($s);

        return ['sections' => $sections, 'seats' => $seats];
    }

    /**
     * 좌석표에 적용: 같은 이름 구역은 좌석 리스트로 바꾸고, 없는 구역은 새로 만든다 (위치는 운영사가 맞춘다).
     * 등급 이름이 있으면 그 등급으로 묶고(없으면 새 등급), 가격이 있으면 등급 가격으로 쓴다.
     *
     * @return array{updated:int, added:int, seats:int, mixed:list<string>}
     */
    public function apply(SeatMap $map, array $parsed): array
    {
        return DB::transaction(function () use ($map, $parsed) {
            $tiers = $map->tiers ?: [];
            $existing = $map->sections()->get()->keyBy(fn ($s) => mb_strtoupper($s->code));
            [$updated, $added, $mixed] = [0, 0, []];
            $i = $existing->count();

            foreach ($parsed['sections'] as $key => $s) {
                $tierKey = null;
                if ($s['grade'] !== null) {
                    $idx = collect($tiers)->search(fn ($t) => $t['name'] === $s['grade']);
                    if ($idx === false) {
                        $n = max(0, ...array_map(fn ($t) => (int) substr($t['key'], 1), $tiers ?: [['key' => 't0']])) + 1;
                        $tiers[] = ['key' => 't'.$n, 'name' => $s['grade'], 'color' => self::COLORS[($n - 1) % count(self::COLORS)], 'price' => (int) ($s['price'] ?? 0), 'seats' => 0];
                        $idx = count($tiers) - 1;
                    } elseif ($s['price'] !== null) {
                        $tiers[$idx]['price'] = $s['price'];
                    }
                    $tierKey = $tiers[$idx]['key'];
                    if (count($s['grades']) > 1) {
                        $mixed[] = $s['code'];
                    }
                }

                $layout = [];
                $n = 0;
                foreach ($s['rows'] as $label => $nums) {
                    $layout[] = ['n' => ++$n, 'label' => (string) $label, 'seats' => $nums];
                }
                $fields = ['layout' => $layout, 'rows' => count($layout), 'seats_per_row' => max(1, ...array_map(fn ($r) => count($r['seats']), $layout))];

                if ($section = $existing->get($key)) {
                    $section->update($fields + ($tierKey ? ['tier' => $tierKey] : []));
                    $updated++;
                } else {
                    if ($tiers === []) {
                        $tiers[] = ['key' => 't1', 'name' => '일반석', 'color' => '#6C4BFF', 'price' => 0, 'seats' => 0];
                    }
                    // 위치를 모르는 새 구역: 왼쪽 위부터 작은 칸으로 늘어놓는다 (편집 화면에서 끌어 맞춘다)
                    [$col, $line] = [$i % 16, intdiv($i, 16)];
                    $map->sections()->create($fields + [
                        'code' => $s['code'], 'tier' => $tierKey ?? $tiers[0]['key'],
                        'x' => round(.01 + $col * .061, 4), 'y' => round(min(.93, .01 + $line * .07), 4), 'w' => .055, 'h' => .06, 'sort' => $i,
                    ]);
                    $i++;
                    $added++;
                }
            }

            // 등급별 좌석 수 다시 세기
            $map->refresh()->load('sections');
            foreach ($tiers as &$t) {
                $t['seats'] = (int) $map->sections->where('tier', $t['key'])->sum(fn (SeatMapSection $s) => $s->capacity());
            }
            unset($t);
            $map->update(['tiers' => $tiers]);

            return ['updated' => $updated, 'added' => $added, 'seats' => $parsed['seats'], 'mixed' => $mixed];
        });
    }

    /** 샘플 CSV (엑셀에서 열어도 한글이 깨지지 않게 BOM 포함) */
    public static function sample(): string
    {
        $lines = ['구역,열,번호,등급,가격', 'A,1,1-20,VIP석,198000', 'A,2,1-22,VIP석,198000', 'A,3,3-24,VIP석,198000',
            'E1,A,1-12,R석,165000', 'E1,B,1-14,R석,165000', 'E1,C,15,R석,165000', 'N3,1,101-130,S석,143000'];

        return "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n";
    }

    private function isHeader(array $cells): bool
    {
        $flat = array_map(fn ($c) => mb_strtolower(str_replace(' ', '', $c)), $cells);
        foreach (self::HEADERS['section'] as $h) {
            if (in_array($h, $flat, true)) {
                return true;
            }
        }

        return false;
    }

    private function columns(array $cells): array
    {
        $cols = [];
        foreach ($cells as $i => $c) {
            $c = mb_strtolower(str_replace(' ', '', $c));
            foreach (self::HEADERS as $field => $names) {
                if (! isset($cols[$field]) && in_array($c, $names, true)) {
                    $cols[$field] = $i;
                }
            }
        }
        if (! isset($cols['section'], $cols['row'], $cols['seat'])) {
            throw ValidationException::withMessages(['seat_list' => '머리글에 구역·열·번호가 있어야 해요.']);
        }

        return $cols;
    }

    /** "12", "12번", "1-18", "1~18" → [1..18]. 읽을 수 없으면 null */
    private function numbers(string $v): ?array
    {
        $v = preg_replace('/\s*번$/u', '', trim($v));
        if (preg_match('/^(\d{1,3})\s*[-~]\s*(\d{1,3})$/', $v, $m)) {
            [$a, $b] = [(int) $m[1], (int) $m[2]];

            return ($a >= 1 && $b >= $a && $b - $a < 300) ? range($a, $b) : null;
        }

        return preg_match('/^\d{1,3}$/', $v) && (int) $v >= 1 ? [(int) $v] : null;
    }
}
