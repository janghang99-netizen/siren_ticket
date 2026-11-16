{{--
    좌석표(배치도 이미지) 좌석도 — 기획사 배치도 위에 구역 박스를 겹친다.
    매진 구역은 어둡게 덮고, $interactive = true 면 부모 x-data 의 pick(id) / selected 와 연결.
--}}
@php($interactive = $interactive ?? false)
@php($map = $event->seatMap)
{{-- 바깥 틀 = 보여줄 영역(배치도만), 안쪽 = 원본 이미지 + 구역 박스(원본 기준 좌표) --}}
<div class="relative overflow-hidden rounded-2xl bg-ink {{ $class ?? 'w-full' }}" style="{{ $map->frameStyle() }}" role="{{ $interactive ? 'group' : 'img' }}"
     aria-label="{{ $event->venue }} 좌석 배치도{{ $interactive ? ' — 구역을 눌러 고르세요' : '' }}">
  <div style="{{ $map->innerStyle() }}">
    <img src="{{ $map->imageUrl() }}" alt="" width="{{ $map->image_w }}" height="{{ $map->image_h }}"
         class="block h-auto w-full select-none" draggable="false" loading="{{ $interactive ? 'eager' : 'lazy' }}" decoding="async">
    @foreach ($event->sections as $s)
        @continue($s->x === null)
        @php($soldOut = $s->remaining() === 0)
        @php($box = sprintf('left:%.2f%%;top:%.2f%%;width:%.2f%%;height:%.2f%%', $s->x * 100, $s->y * 100, $s->w * 100, $s->h * 100))
        @if ($interactive && ! $soldOut)
            <button type="button" style="{{ $box }}" class="seat-box absolute rounded-[4px] outline-none transition"
                    :class="selected === {{ $s->id }} ? 'z-10 bg-ink/55 ring-[3px] ring-white shadow-[0_0_0_5px_rgba(23,19,43,.85)]' : 'hover:bg-white/35 hover:ring-2 hover:ring-white focus-visible:ring-2 focus-visible:ring-white'"
                    @click="pick({{ $s->id }})"
                    aria-label="{{ $s->code }}구역 {{ $s->tier }} {{ number_format($s->price) }}원, 잔여 {{ number_format($s->remaining()) }}석">
                <span x-show="selected === {{ $s->id }}" x-cloak class="num absolute inset-0 grid place-items-center text-[11px] font-black text-white">{{ $s->code }}</span>
            </button>
        @elseif ($soldOut)
            <span style="{{ $box }}" class="absolute grid place-items-center rounded-[4px] bg-ink/70 text-[9px] font-bold text-white/80" title="{{ $s->code }}구역 매진">매진</span>
        @endif
    @endforeach
  </div>
</div>
