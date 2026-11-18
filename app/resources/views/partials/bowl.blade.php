{{--
    아레나 보울 좌석도. 구역마다 호(arc) 하나.
    남은 좌석이 많을수록 응원봉처럼 밝게, 매진이면 불이 꺼진다.
    $interactive = true 면 부모 x-data 의 pick(id) / selected 와 연결.
--}}
@php($interactive = $interactive ?? false)
<svg viewBox="0 0 400 400" class="{{ $class ?? 'w-full' }}" role="{{ $interactive ? 'group' : 'img' }}"
     aria-label="{{ $event->venue }} 좌석도{{ $interactive ? ' — 구역을 눌러 고르세요' : '' }}">
    <defs>
        <radialGradient id="glow-{{ $event->id }}" cx="50%" cy="8%" r="70%">
            <stop offset="0%" stop-color="{{ $event->theme_color }}" stop-opacity=".35"/>
            <stop offset="100%" stop-color="{{ $event->theme_color }}" stop-opacity="0"/>
        </radialGradient>
    </defs>
    <circle cx="200" cy="200" r="198" fill="url(#glow-{{ $event->id }})"/>

    {{-- 무대 --}}
    <rect x="140" y="14" width="120" height="34" rx="10" fill="#17132B"/>
    <text x="200" y="36" text-anchor="middle" fill="#F1EEF8" font-family="Unbounded" font-size="12" font-weight="700" letter-spacing="3">STAGE</text>

    @foreach ($event->sections as $s)
        @php($ratio = $s->capacity ? $s->remaining() / $s->capacity : 0)
        @php($soldOut = $s->remaining() === 0)
        @php($opacity = number_format(.3 + .7 * $ratio, 2))
        @php($fill = ($tierColors ?? [])[$s->tier] ?? $event->theme_color)   {{-- 좌석 선택 화면은 등급 색 --}}
        <path d="{{ $s->arcPath() }}"
              class="seat-arc"
              stroke="#F1EEF8" stroke-width="3"
              fill="{{ $soldOut ? '#17132B' : $fill }}"
              fill-opacity="{{ $soldOut ? '.10' : $opacity }}"
              @if ($interactive)
                  role="button" tabindex="{{ $soldOut ? -1 : 0 }}"
                  aria-disabled="{{ $soldOut ? 'true' : 'false' }}"
                  aria-label="{{ $s->code }}구역 {{ $s->tier }}석 {{ number_format($s->price) }}원, {{ $soldOut ? '매진' : '잔여 '.number_format($s->remaining()).'석' }}"
                  @unless ($soldOut)
                      @click="pick({{ $s->id }})" @keydown.enter.prevent="pick({{ $s->id }})" @keydown.space.prevent="pick({{ $s->id }})"
                      :fill="selected === {{ $s->id }} ? '#17132B' : '{{ $fill }}'"
                      :fill-opacity="selected === {{ $s->id }} ? 1 : {{ $opacity }}"
                  @endunless
              @endif
        ><title>{{ $s->code }}구역 · {{ $soldOut ? '매진' : '잔여 '.number_format($s->remaining()).'석' }}</title></path>
        @php($mid = deg2rad(($s->start_deg + $s->end_deg) / 2 + 90))
        @php($r = [90, 135, 176][$s->ring] ?? 176)
        <text x="{{ round(200 + $r * cos($mid), 1) }}" y="{{ round(204 + $r * sin($mid), 1) }}" text-anchor="middle"
              font-family="Unbounded" font-size="10" font-weight="700" pointer-events="none"
              fill="{{ $soldOut ? '#8A84A3' : '#17132B' }}" @if ($interactive && ! $soldOut) :fill="selected === {{ $s->id }} ? '#ffffff' : '#17132B'" @endif>{{ $s->code }}</text>
    @endforeach
</svg>
