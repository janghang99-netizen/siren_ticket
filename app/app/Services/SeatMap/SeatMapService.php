<?php

namespace App\Services\SeatMap;

use App\Models\Event;
use App\Models\SeatMap;
use App\Models\SeatSection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 좌석표: 이미지 저장 → (AI 판독) 초안 → 운영사 편집 → 공연에 복사.
 * 좌석 = 구역마다 열 × 열당 좌석 격자. 공연에 복사한 뒤에는 좌석표를 고쳐도 그 공연은 바뀌지 않는다.
 */
class SeatMapService
{
    public const MAX_SIDE = 2400;          // 작은 구역 글자도 읽히게 크게 둔다

    public const DEFAULT_PER_ROW = 20;

    public const MAX_SECTIONS = 500;

    /** @return array{path:string,w:int,h:int} */
    public function storeImage(UploadedFile $file): array
    {
        $name = 'seat-maps/'.Str::random(40);
        $bytes = (string) file_get_contents($file->getRealPath());
        $src = function_exists('imagecreatefromstring') ? @imagecreatefromstring($bytes) : false;

        if ($src && function_exists('imagewebp')) {
            [$w, $h] = [imagesx($src), imagesy($src)];
            $scale = min(1, self::MAX_SIDE / max($w, $h));
            [$nw, $nh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            imagewebp($dst, null, 90);
            Storage::disk('local')->put($name.'.webp', (string) ob_get_clean());

            return ['path' => $name.'.webp', 'w' => $nw, 'h' => $nh];
        }

        [$w, $h] = getimagesizefromstring($bytes) ?: [1000, 1000];
        $ext = strtolower($file->extension() ?: 'png');
        Storage::disk('local')->put($name.'.'.$ext, $bytes);

        return ['path' => $name.'.'.$ext, 'w' => (int) $w, 'h' => (int) $h];
    }

    public function mediaType(SeatMap $map): string
    {
        return match (strtolower(pathinfo($map->image_path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', default => 'image/webp',
        };
    }

    /** AI 판독 결과 → 편집 가능한 초안 (등급 키 부여, 좌표 정리, 등급 좌석 수대로 열·좌석 나누기) */
    public function draft(array $read): array
    {
        $tiers = [];
        foreach (array_slice($read['tiers'] ?? [], 0, 20) as $i => $t) {
            $tiers[] = [
                'key' => 't'.($i + 1),
                'name' => mb_substr(trim((string) ($t['name'] ?? '')), 0, 30) ?: '등급 '.($i + 1),
                'color' => $this->color($t['color'] ?? ''),
                'price' => max(0, min(10_000_000, (int) ($t['price'] ?? 0))),
                'seats' => max(0, min(200_000, (int) ($t['seats'] ?? 0))),
            ];
        }
        if ($tiers === []) {
            $tiers[] = ['key' => 't1', 'name' => '일반', 'color' => '#6C4BFF', 'price' => 0, 'seats' => 0];
        }

        $sections = [];
        $used = [];
        foreach (array_slice($read['sections'] ?? [], 0, self::MAX_SECTIONS) as $s) {
            $code = $this->uniqueCode((string) ($s['code'] ?? ''), $used);
            $tier = $tiers[(int) ($s['tier'] ?? 0)] ?? $tiers[0];
            [$x, $y, $w, $h] = $this->box((float) ($s['x'] ?? 0), (float) ($s['y'] ?? 0), (float) ($s['w'] ?? .05), (float) ($s['h'] ?? .05));
            $sections[] = ['code' => $code, 'tier' => $tier['key'], 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
                'rows' => 1, 'seats_per_row' => self::DEFAULT_PER_ROW];
        }

        return ['tiers' => $tiers, 'sections' => $this->distribute($tiers, $sections)];
    }

    /**
     * 등급 좌석 수를 그 등급 구역들에 고르게 나눠 열 × 열당 좌석을 정한다 (열당 최대 20석).
     * 등급 좌석 수를 모르면(0) 구역당 1열 20석으로 둔다.
     */
    public function distribute(array $tiers, array $sections): array
    {
        $count = array_count_values(array_column($sections, 'tier'));
        $seats = array_column($tiers, 'seats', 'key');

        return array_map(function ($s) use ($count, $seats) {
            $total = (int) ($seats[$s['tier']] ?? 0);
            if ($total > 0) {
                $per = (int) ceil($total / max(1, $count[$s['tier']] ?? 1));
                $s['seats_per_row'] = min(self::DEFAULT_PER_ROW, max(1, $per));
                $s['rows'] = min(300, (int) ceil($per / $s['seats_per_row']));
            }

            return $s;
        }, $sections);
    }

    /**
     * 편집 화면에서 보낸 좌석표 검증·정리. 틀린 곳은 ValidationException(payload) 으로 돌려준다.
     *
     * @return array{tiers:array,sections:array}
     */
    public function normalize(mixed $payload): array
    {
        $fail = function (string $msg): never {
            throw ValidationException::withMessages(['payload' => $msg]);
        };
        if (! is_array($payload) || ! is_array($payload['tiers'] ?? null) || ! is_array($payload['sections'] ?? null)) {
            $fail('좌석표 내용을 읽지 못했어요. 새로고침 후 다시 저장해 주세요.');
        }
        if ($payload['tiers'] === [] || count($payload['tiers']) > 20) {
            $fail('등급은 1~20개여야 해요.');
        }
        if (count($payload['sections']) > self::MAX_SECTIONS) {
            $fail('구역은 '.self::MAX_SECTIONS.'개까지 만들 수 있어요.');
        }

        $tiers = [];
        foreach ($payload['tiers'] as $t) {
            $key = (string) ($t['key'] ?? '');
            $name = mb_substr(trim((string) ($t['name'] ?? '')), 0, 30);
            if (! preg_match('/^t\d{1,4}$/', $key) || isset($tiers[$key])) {
                $fail('등급 정보가 올바르지 않아요.');
            }
            if ($name === '') {
                $fail('등급 이름을 적어 주세요.');
            }
            $tiers[$key] = ['key' => $key, 'name' => $name, 'color' => $this->color($t['color'] ?? ''),
                'price' => max(0, min(10_000_000, (int) ($t['price'] ?? 0))),
                'seats' => max(0, min(200_000, (int) ($t['seats'] ?? 0)))];
        }

        $sections = [];
        $codes = [];
        foreach ($payload['sections'] as $s) {
            $code = trim((string) ($s['code'] ?? ''));
            if (! preg_match('/^[\p{L}\p{N}\-_.]{1,10}$/u', $code)) {
                $fail("구역 이름 ‘{$code}’ 은(는) 쓸 수 없어요. 글자·숫자·-·_ 10자 이내로 적어 주세요.");
            }
            if (isset($codes[mb_strtoupper($code)])) {
                $fail("구역 이름 ‘{$code}’ 이(가) 두 번 있어요. 구역 이름은 겹치면 안 돼요.");
            }
            $codes[mb_strtoupper($code)] = true;
            if (! isset($tiers[$s['tier'] ?? ''])) {
                $fail("{$code} 구역의 등급을 골라 주세요.");
            }
            $rows = (int) ($s['rows'] ?? 0);
            $per = (int) ($s['seats_per_row'] ?? 0);
            if ($rows < 1 || $rows > 300 || $per < 1 || $per > 200) {
                $fail("{$code} 구역의 열 수(1~300)·열당 좌석(1~200)을 확인해 주세요.");
            }
            [$x, $y, $w, $h] = $this->box((float) ($s['x'] ?? 0), (float) ($s['y'] ?? 0), (float) ($s['w'] ?? 0), (float) ($s['h'] ?? 0));
            $sections[] = ['code' => $code, 'tier' => $s['tier'], 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'rows' => $rows, 'seats_per_row' => $per];
        }

        return ['tiers' => array_values($tiers), 'sections' => $sections];
    }

    /** 등급·구역 저장 (구역은 통째로 바꾼다 — 이미 만든 공연에는 영향 없음) */
    public function sync(SeatMap $map, array $tiers, array $sections): void
    {
        DB::transaction(function () use ($map, $tiers, $sections) {
            $map->update(['tiers' => $tiers]);
            $map->sections()->delete();
            foreach ($sections as $i => $s) {
                $map->sections()->create($s + ['sort' => $i]);
            }
        });
    }

    /** 공연에 좌석표 복사: 구역마다 공연 좌석 구역(가격 = 등급 가격, 좌석 = 열 × 열당 좌석) */
    public function applyToEvent(Event $event, SeatMap $map): int
    {
        $map->loadMissing('sections');
        if ($map->sections->isEmpty()) {
            throw ValidationException::withMessages(['seat_map_id' => '이 좌석표에는 구역이 없어요. 좌석표에서 구역을 먼저 만들어 주세요.']);
        }
        foreach ($map->sections as $s) {
            $tier = $map->tier($s->tier) ?? ['name' => '일반', 'color' => '#6C4BFF', 'price' => 0];
            SeatSection::create([
                'event_id' => $event->id, 'code' => $s->code, 'name' => $s->code.'구역',
                'tier' => mb_substr($tier['name'], 0, 10), 'color' => $tier['color'], 'price' => (int) $tier['price'],
                'capacity' => $s->capacity(), 'ring' => 0, 'start_deg' => 0, 'end_deg' => 0,
                'x' => $s->x, 'y' => $s->y, 'w' => $s->w, 'h' => $s->h, 'rows' => $s->rows, 'seats_per_row' => $s->seats_per_row,
            ]);
        }
        $event->update(['seat_map_id' => $map->id]);

        return $map->sections->count();
    }

    private function color(mixed $c): string
    {
        $c = strtoupper(trim((string) $c));
        if (preg_match('/^#?([0-9A-F]{3})$/', $c, $m)) {
            $c = '#'.implode('', array_map(fn ($ch) => $ch.$ch, str_split($m[1])));
        }

        return preg_match('/^#?([0-9A-F]{6})$/', $c, $m) ? '#'.$m[1] : '#6C4BFF';
    }

    /** @return array{0:float,1:float,2:float,3:float} 0~1 안으로 자른 박스 (최소 크기 0.5%) */
    private function box(float $x, float $y, float $w, float $h): array
    {
        $x = max(0, min(.995, $x));
        $y = max(0, min(.995, $y));
        $w = max(.005, min(1 - $x, $w));
        $h = max(.005, min(1 - $y, $h));

        return [round($x, 4), round($y, 4), round($w, 4), round($h, 4)];
    }

    private function uniqueCode(string $code, array &$used): string
    {
        $code = mb_substr(preg_replace('/[^\p{L}\p{N}\-_.]/u', '', $code) ?: 'S', 0, 10);
        $base = $code;
        for ($n = 2; isset($used[mb_strtoupper($code)]); $n++) {
            $code = mb_substr($base, 0, 10 - strlen('-'.$n)).'-'.$n;
        }
        $used[mb_strtoupper($code)] = true;

        return $code;
    }
}
