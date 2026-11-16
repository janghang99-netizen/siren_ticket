<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 전국 공연장 DB 한 줄 (KOPIS 공연시설 또는 기본 목록). 운영사가 공연장을 추가할 때 검색해서 불러온다. */
class VenueCatalog extends Model
{
    protected $table = 'venue_catalog';

    protected $guarded = ['id'];

    protected $casts = ['halls' => 'array', 'seats' => 'integer', 'lat' => 'float', 'lng' => 'float', 'synced_at' => 'datetime'];

    /** KOPIS 상세(주소·객석·홀)를 아직 안 받았는지 */
    public function needsDetail(): bool
    {
        return $this->source === 'kopis' && $this->address === null;
    }

    public function region(): string
    {
        return trim(($this->sido ?? '').' '.($this->gugun ?? ''));
    }

    public function toPick(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'region' => $this->region(), 'address' => $this->address,
            'seats' => $this->seats, 'halls' => $this->halls ?? [], 'source' => $this->source,
        ];
    }
}
