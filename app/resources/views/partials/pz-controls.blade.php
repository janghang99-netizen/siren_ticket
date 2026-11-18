{{-- 확대·축소 버튼 (window.panZoom 과 함께) --}}
<div class="absolute bottom-3 right-3 flex flex-col overflow-hidden rounded-2xl bg-white shadow-lg ring-1 ring-ink/10" role="group" aria-label="확대·축소">
    <button type="button" @click="zoomBtn(1.3)" class="grid h-11 w-11 place-items-center text-xl font-bold hover:bg-paper" aria-label="확대">+</button>
    <button type="button" @click="zoomBtn(1 / 1.3)" class="grid h-11 w-11 place-items-center border-y border-ink/[.08] text-xl font-bold hover:bg-paper" aria-label="축소">−</button>
    <button type="button" @click="fit()" class="grid h-11 w-11 place-items-center hover:bg-paper" aria-label="화면에 맞추기">
        <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>
    </button>
</div>
<p class="pointer-events-none absolute left-3 top-3 rounded-full bg-white/85 px-2.5 py-1 text-[11px] font-semibold text-mist ring-1 ring-ink/[.06] backdrop-blur">휠·두 손가락으로 확대 · 끌어서 이동</p>
