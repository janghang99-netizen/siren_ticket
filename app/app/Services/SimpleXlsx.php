<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * 시트 여러 개짜리 xlsx 를 라이브러리 없이 만든다 (정산 보고서 엑셀 — 원본은 브라우저 SheetJS 로 만들었음).
 *  숫자는 숫자 셀, 나머지는 글자 셀(inlineStr). 서식·수식 없음.
 */
class SimpleXlsx
{
    /**
     * @param  array<string, array<int, array<int, mixed>>>  $sheets  시트 이름 => 줄 목록
     * @param  array<string, array<int, int>>  $widths  시트 이름 => 칸 너비(글자 수)
     * @return string xlsx 바이너리
     */
    public static function make(array $sheets, array $widths = []): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('서버에 zip 기능이 없어 엑셀을 만들 수 없어요.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $names = array_keys($sheets);
        $n = count($names);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .implode('', array_map(fn ($i) => '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', range(1, $n)))
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            .implode('', array_map(fn ($i) => '<sheet name="'.self::esc(mb_substr($names[$i - 1], 0, 31)).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>', range(1, $n)))
            .'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .implode('', array_map(fn ($i) => '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>', range(1, $n)))
            .'</Relationships>');

        foreach ($names as $i => $name) {
            $cols = '';
            if (! empty($widths[$name])) {
                $cols = '<cols>'.implode('', array_map(fn ($w, $c) => '<col min="'.($c + 1).'" max="'.($c + 1).'" width="'.(int) $w.'" customWidth="1"/>', $widths[$name], array_keys($widths[$name]))).'</cols>';
            }
            $xml = '';
            foreach (array_values($sheets[$name]) as $r => $row) {
                $xml .= '<row r="'.($r + 1).'">';
                foreach (array_values($row) as $c => $v) {
                    $ref = self::col($c).($r + 1);
                    if (is_int($v) || is_float($v)) {
                        $xml .= '<c r="'.$ref.'"><v>'.(is_float($v) ? round($v) : $v).'</v></c>';
                    } elseif ($v !== null && $v !== '') {
                        $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.self::esc((string) $v).'</t></is></c>';
                    }
                }
                $xml .= '</row>';
            }
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$cols.'<sheetData>'.$xml.'</sheetData></worksheet>');
        }
        $zip->close();
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bin;
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
