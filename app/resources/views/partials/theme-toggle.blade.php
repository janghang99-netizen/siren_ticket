{{-- 다크 ↔ 라이트 전환 버튼 (지금 모드의 반대 아이콘을 보여 준다) --}}
<button type="button" onclick="toggleTheme()" class="theme-toggle grid h-10 w-10 shrink-0 place-items-center rounded-full text-ink transition hover:bg-paper" aria-label="화면 밝기 바꾸기 (다크·라이트)" title="다크·라이트 모드">
    <svg class="theme-icon-sun h-[19px] w-[19px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6 6 6M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4"/></svg>
    <svg class="theme-icon-moon h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5Z"/></svg>
</button>
