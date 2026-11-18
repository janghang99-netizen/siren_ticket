<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** 작품 — 회차(Event)들을 묶는다. 메인·장르 목록·상세에서 보이는 단위 */
class Production extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'is_featured' => 'boolean',
        'runtime_minutes' => 'integer',
        'sort' => 'integer',
    ];

    /** 장르 (주소용 키 => 이름) — 메인 장르 줄 순서 */
    public const GENRES = [
        'concert' => '콘서트',
        'musical' => '뮤지컬',
        'fanmeeting' => '팬미팅',
        'festival' => '페스티벌',
        'classic' => '클래식',
        'theater' => '연극',
        'sports' => '스포츠',
        'exhibition' => '전시',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class)->orderBy('starts_at');
    }

    /** 아직 끝나지 않은(취소 아닌) 회차가 있는 작품 */
    public function scopeShowing(Builder $q): Builder
    {
        return $q->whereHas('events', fn ($e) => $e->where('status', 'scheduled')->where('starts_at', '>', now()));
    }

    public function genreName(): string
    {
        return self::GENRES[$this->genre] ?? $this->genre;
    }

    /** 다가오는 회차 (events 를 미리 불러왔으면 그걸 쓴다) */
    public function upcoming()
    {
        return $this->events->filter(fn (Event $e) => ! $e->isCancelled() && $e->starts_at->isFuture())->values();
    }

    public function firstDate(): ?Carbon
    {
        return $this->events->min('starts_at');
    }

    public function lastDate(): ?Carbon
    {
        return $this->events->max('starts_at');
    }

    /** 기간 표시: 하루면 26.12.06, 여러 날이면 26.12.06 ~ 26.12.07 */
    public function period(string $format = 'y.m.d'): string
    {
        $first = $this->firstDate();
        $last = $this->lastDate();
        if (! $first) {
            return '';
        }

        return $first->isSameDay($last) ? $first->format($format) : $first->format($format).' ~ '.$last->format($format);
    }

    /** 가장 이른 예매 오픈 시각 (다가오는 회차 기준) */
    public function salesOpenAt(): ?Carbon
    {
        return $this->upcoming()->pluck('sales_open_at')->filter()->min();
    }

    /** 예매 오픈 전 (모든 다가오는 회차가 아직 오픈 전) */
    public function opensLater(): bool
    {
        $upcoming = $this->upcoming();

        return $upcoming->isNotEmpty() && $upcoming->every(fn (Event $e) => $e->sales_open_at?->isFuture());
    }

    /** 가장 싼 좌석 가격 (회차의 구역을 미리 불러왔을 때) */
    public function lowestPrice(): ?int
    {
        $min = $this->upcoming()->flatMap(fn (Event $e) => $e->sections)->min('price');

        return $min === null ? null : (int) $min;
    }

    public function posterUrl(): ?string
    {
        return $this->poster_path ? route('media.production', basename($this->poster_path)) : null;
    }

    public function bannerUrl(): ?string
    {
        return $this->banner_path ? route('media.production', basename($this->banner_path)) : null;
    }

    /** 상세로 갈 회차 — 지금은 가장 가까운 다가오는 회차 (작품 상세 화면을 만들면 작품 주소로 바꾼다) */
    public function nextEvent(): ?Event
    {
        return $this->upcoming()->first();
    }
}
