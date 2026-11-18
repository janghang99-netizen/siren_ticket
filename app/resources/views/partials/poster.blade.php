{{-- 작품 포스터 (3:4). 이미지가 없으면 작품 색으로 만든 글자 포스터. $p = Production, $eager = 첫 화면이면 true --}}
<div class="poster" style="--theme: {{ $p->theme_color }}">
    @if ($p->posterUrl())
        <img src="{{ $p->posterUrl() }}" alt="{{ $p->title }} 포스터" @unless ($eager ?? false) loading="lazy" @endunless decoding="async">
    @else
        {{-- 글자는 위쪽에만 — 아래 왼쪽은 랭킹 숫자 자리 --}}
        <div class="poster-gen theme-keep flex h-full flex-col p-4 text-white" role="img" aria-label="{{ $p->title }}">
            <span class="font-display text-[10px] font-bold uppercase tracking-[.2em] text-white/60 {{ $p->badge ? 'mt-7' : '' }}">{{ $p->genreName() }}</span>
            <span class="mt-2 block font-display text-[clamp(18px,4.2vw,28px)] font-black leading-[1.02] tracking-tight [word-break:keep-all]">{{ $p->artist ?: $p->title }}</span>
            <span class="mt-2 block text-[11px] font-semibold text-white/70">{{ $p->period('m.d') }} · {{ $p->venue }}</span>
            <svg class="mt-auto h-8 w-8 self-end text-white/25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2v-2a2 2 0 0 0 0-4Z"/><path d="M9 6v12" stroke-dasharray="2 2.5"/></svg>
        </div>
    @endif
    @if ($p->badge)
        <span class="absolute left-2 top-2 rounded-md bg-pick px-1.5 py-0.5 text-[11px] font-bold text-ink">{{ $p->badge }}</span>
    @endif
</div>
