{{-- 도면 미리보기 (편집기로 그린 좌석표) — 좌석 계산 모듈(window.SeatPlan.thumb)로 브라우저가 그린다. $m = SeatMap(kind=plan) --}}
@php($secs = $m->sections->map(fn ($s) => ['code' => $s->code, 'tier' => $s->tier, 'shape' => $s->shape, 'rows' => $s->rows, 'per' => $s->seats_per_row, 'layout' => $s->layout])->values())
<svg viewBox="-20 -20 1040 1040" class="block w-full bg-paper {{ $class ?? '' }}" role="img" aria-label="{{ $m->name }} 도면"
     x-data x-init="$el.innerHTML = window.SeatPlan.thumb(@js($m->plan), @js($secs), @js($m->tiers))"></svg>
