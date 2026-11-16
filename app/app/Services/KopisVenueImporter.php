<?php

namespace App\Services;

use App\Models\VenueCatalog;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

/**
 * 공연예술통합전산망(KOPIS) 공연시설 → 전국 공연장 DB.
 *  - 목록: GET {BASE}prfplc?service=KEY&cpage=N&rows=500  → <dbs><db>mt10id, fcltynm, sidonm, gugunnm, fcltychartr …</db></dbs>
 *  - 상세: GET {BASE}prfplc/{mt10id}?service=KEY          → adres, seatscale, la, lo, telno, relateurl, mt13s/mt13(prfplcnm, mt13id, seatscale)
 * 오류는 <db><returncode>02</returncode><errmsg>…</errmsg></db> 로 온다.
 * 서비스 키: 환경 변수 KOPIS_API_KEY (config services.kopis.key). 키는 로그·화면에 남기지 않는다.
 */
class KopisVenueImporter
{
    public const BASE = 'https://kopis.or.kr/openApi/restful/';

    public const ROWS = 500;

    public function __construct(private readonly ?string $key) {}

    public function ready(): bool
    {
        return (string) $this->key !== '';
    }

    /**
     * 전국 공연시설 목록을 받아 이름·지역을 넣거나 고친다 (상세는 따로).
     *
     * @return array{added:int, updated:int}
     */
    public function importList(?callable $progress = null): array
    {
        $added = $updated = 0;
        for ($page = 1; $page <= 100; $page++) {
            $rows = $this->get('prfplc', ['cpage' => $page, 'rows' => self::ROWS]);
            foreach ($rows as $db) {
                $id = trim((string) $db->mt10id);
                if ($id === '') {
                    continue;
                }
                $row = VenueCatalog::firstOrNew(['kopis_id' => $id]);
                $row->exists ? $updated++ : $added++;
                $row->fill([
                    'source' => 'kopis', 'name' => mb_substr(trim((string) $db->fcltynm), 0, 100),
                    'sido' => $this->text($db->sidonm), 'gugun' => $this->text($db->gugunnm), 'kind' => $this->text($db->fcltychartr),
                ])->save();
            }
            $progress && $progress($page, count($rows));
            if (count($rows) < self::ROWS) {
                break;
            }
        }

        return ['added' => $added, 'updated' => $updated];
    }

    /** 시설 하나의 상세(주소·객석·위치·홀 목록)를 받아 채운다 */
    public function fillDetail(VenueCatalog $row): VenueCatalog
    {
        if ($row->source !== 'kopis' || ! $row->kopis_id) {
            return $row;
        }
        $db = $this->get('prfplc/'.rawurlencode($row->kopis_id))[0] ?? null;
        if (! $db) {
            return $row;
        }
        $halls = [];
        foreach ($db->mt13s->mt13 ?? [] as $h) {
            $halls[] = ['id' => (string) $h->mt13id, 'name' => trim((string) $h->prfplcnm), 'seats' => $this->int($h->seatscale)];
        }
        $row->fill([
            'name' => mb_substr(trim((string) $db->fcltynm), 0, 100) ?: $row->name,
            'address' => $this->text($db->adres) ?? '', 'seats' => $this->int($db->seatscale),
            'lat' => is_numeric((string) $db->la) ? (float) $db->la : null, 'lng' => is_numeric((string) $db->lo) ? (float) $db->lo : null,
            'phone' => $this->text($db->telno), 'url' => $this->text($db->relateurl), 'halls' => $halls, 'synced_at' => now(),
        ])->save();

        return $row;
    }

    /** @return list<SimpleXMLElement> <db> 목록 */
    private function get(string $path, array $params = []): array
    {
        if (! $this->ready()) {
            throw new RuntimeException('KOPIS_API_KEY 가 설정되지 않았어요.');
        }
        $res = Http::timeout(20)->retry(2, 500, throw: false)->get(self::BASE.$path, ['service' => $this->key] + $params);
        if (! $res->successful()) {
            throw new RuntimeException('KOPIS 응답 오류 (HTTP '.$res->status().')');
        }
        $xml = @simplexml_load_string($res->body(), SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        if ($xml === false) {
            throw new RuntimeException('KOPIS 응답을 읽지 못했어요.');
        }
        $rows = [];
        foreach ($xml->db ?? [] as $db) {
            if (isset($db->returncode) && (string) $db->returncode !== '00') {
                throw new RuntimeException('KOPIS 오류: '.mb_substr((string) $db->errmsg, 0, 120));
            }
            $rows[] = $db;
        }

        return $rows;
    }

    private function text(mixed $v): ?string
    {
        $s = trim((string) $v);

        return $s === '' ? null : mb_substr($s, 0, 255);
    }

    private function int(mixed $v): ?int
    {
        $n = preg_replace('/\D/', '', (string) $v);

        return $n === '' ? null : (int) $n;
    }
}
