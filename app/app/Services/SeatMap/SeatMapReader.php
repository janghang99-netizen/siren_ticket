<?php

namespace App\Services\SeatMap;

/**
 * 좌석 배치도 이미지 → 등급·구역 초안. 결과는 운영사가 편집 화면에서 확인·수정한 뒤 확정한다.
 */
interface SeatMapReader
{
    public function ready(): bool;

    /**
     * @param  string  $image  이미지 바이트
     * @return array{
     *     tiers: list<array{name:string,color:string,price:int,seats:int}>,
     *     sections: list<array{code:string,tier:int,x:float,y:float,w:float,h:float}>
     * } sections.tier 는 tiers 의 0부터 시작하는 순번, x·y·w·h 는 이미지 너비·높이 대비 0~1
     *
     * @throws SeatMapReadFailed
     */
    public function read(string $image, string $mediaType): array;
}
