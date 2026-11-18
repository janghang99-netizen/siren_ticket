{{-- 메인: 모든 티켓은 블록체인 티켓 — 실제 동작 그대로 (NftTicketService · SirenTicket.sol: ERC-721 + ERC-5192 잠금, 입장 사용 표시, 취소 소각) --}}
<section class="tix-wrap mt-16" aria-labelledby="chain-title">
    <div class="theme-keep chain-band relative overflow-hidden rounded-[32px] px-6 py-12 text-white sm:px-10 lg:grid lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] lg:items-center lg:gap-12 lg:px-14 lg:py-16">
        <div class="chain-grid pointer-events-none absolute inset-0" aria-hidden="true"></div>

        <div class="relative">
            <p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1.5 font-display text-[11px] font-bold tracking-[.22em] text-pick ring-1 ring-white/15">
                <span class="chain-dot h-1.5 w-1.5 rounded-full bg-pick"></span>BLOCKCHAIN TICKET
            </p>
            <h2 id="chain-title" class="mt-5 text-[30px] font-black leading-[1.12] tracking-tight [word-break:keep-all] sm:text-[38px] lg:text-[44px]">
                모든 티켓은<br><span class="chain-shine">블록체인 위에 새겨집니다</span>
            </h2>
            <p class="mt-4 max-w-xl text-[15px] leading-relaxed text-white/70">
                예매하는 순간 티켓 한 장 한 장이 <b class="text-white">카이아(Kaia) 블록체인</b>에 고유한 토큰으로 발행돼요.
                누구도 위조할 수 없고, 되팔 수 없고, 한 번 입장하면 다시 쓸 수 없어요.
            </p>

            <ul class="mt-8 grid gap-3 sm:grid-cols-2">
                @foreach ([
                    ['위조할 수 없어요', '티켓마다 고유 토큰이 블록체인에 기록돼, 복사하거나 만들어 낼 수 없어요.', '<path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6Z"/><path d="m9 12 2 2 4-4"/>'],
                    ['암표로 되팔 수 없어요', '토큰이 잠겨 있어 다른 사람 지갑으로 옮길 수 없어요. 예매한 내가 관람해요.', '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>'],
                    ['캡처로는 못 들어가요', '입장 코드는 30초마다 바뀌고, 검표하면 블록체인에 ‘사용됨’이 한 번만 남아요.', '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M9 9h2v2H9zM13 13h2v2h-2zM9 13h.01M15 9h.01"/><path d="M12 2v2M12 20v2"/>'],
                    ['개인정보는 올리지 않아요', '블록체인에는 공연·좌석만 남고 이름·전화번호는 올라가지 않아요.', '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/><path d="M3 3l18 18"/>'],
                ] as [$t, $d, $icon])
                    <li class="flex gap-3 rounded-2xl bg-white/[.06] p-4 ring-1 ring-white/10 backdrop-blur">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-pick/15 text-pick">
                            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icon !!}</svg>
                        </span>
                        <span>
                            <span class="block text-[15px] font-bold">{{ $t }}</span>
                            <span class="mt-0.5 block text-[13px] leading-snug text-white/60">{{ $d }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
            <a href="{{ route('about.blockchain') }}" class="mt-8 inline-flex h-12 items-center gap-2 rounded-full bg-pick px-6 text-[15px] font-bold text-ink transition hover:brightness-95">블록체인 티켓 자세히 보기 <span aria-hidden="true">→</span></a>
        </div>

        {{-- 그림: 토큰이 된 티켓 + 이어지는 블록 --}}
        <div class="relative mx-auto mt-12 w-full max-w-[400px] lg:mt-0" aria-hidden="true">
            <div class="chain-ticket relative rotate-[-2deg] rounded-[22px] bg-white p-5 text-ink shadow-[0_40px_80px_-30px_rgba(0,0,0,.7)]">
                <div class="flex items-start justify-between">
                    <span class="font-display text-[11px] font-bold tracking-[.2em] text-mist">NFT TICKET</span>
                    <span class="inline-flex items-center gap-1 rounded-full bg-ink px-2 py-1 font-display text-[10px] font-bold text-pick">
                        <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2.6"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>LOCKED
                    </span>
                </div>
                <p class="mt-4 text-xl font-black leading-tight">LIVE IN SEOUL</p>
                <p class="mt-1 text-sm text-mist">VIP석 · F3구역 4열 12번</p>
                <div class="my-4 border-t-2 border-dashed border-ink/10"></div>
                <dl class="space-y-2 font-display text-[11px]">
                    @foreach (['TOKEN' => '#000128', 'NETWORK' => 'KAIA', 'STANDARD' => 'ERC-721 · 5192', 'HASH' => '0x9f3a…c41e'] as $k => $v)
                        <div class="flex items-center justify-between gap-3"><dt class="text-mist">{{ $k }}</dt><dd class="font-bold">{{ $v }}</dd></div>
                    @endforeach
                </dl>
            </div>
            <div class="mt-8 flex items-center justify-center gap-2.5">
                @foreach (['예매', '발행', '입장', '사용됨'] as $k => $label)
                    <span class="chain-block grid h-[58px] w-[64px] place-items-center rounded-xl bg-white/[.07] text-center ring-1 ring-white/15" style="--d: {{ $k * .45 }}s">
                        <span><span class="block font-display text-[9px] font-bold text-white/40">#{{ 4021 + $k }}</span><span class="block text-[12px] font-bold">{{ $label }}</span></span>
                    </span>
                    @if (! $loop->last)<span class="chain-link h-0.5 w-3 rounded-full bg-pick/60" style="--d: {{ $k * .45 + .2 }}s"></span>@endif
                @endforeach
            </div>
        </div>
    </div>
</section>

<style>
    .chain-band { background: radial-gradient(90% 70% at 85% 10%, rgba(108, 75, 255, .45) 0%, transparent 60%), radial-gradient(70% 60% at 0% 100%, rgba(255, 210, 63, .14) 0%, transparent 60%), linear-gradient(160deg, #15122A 0%, #0B0A12 100%); }
    .chain-grid { background-image: linear-gradient(rgba(255,255,255,.05) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.05) 1px, transparent 1px);
        background-size: 34px 34px; mask-image: radial-gradient(70% 70% at 70% 40%, #000 0%, transparent 75%); -webkit-mask-image: radial-gradient(70% 70% at 70% 40%, #000 0%, transparent 75%); }
    .chain-shine { background: linear-gradient(90deg, #FFD23F, #FFF3C4 45%, #B9A6FF 80%); -webkit-background-clip: text; background-clip: text; color: transparent; }
    .chain-dot { animation: chain-pulse 1.8s ease-in-out infinite; }
    .chain-block, .chain-link { animation: chain-lit 3.6s ease-in-out infinite; animation-delay: var(--d); }
    .chain-ticket { animation: chain-float 6s ease-in-out infinite; }
    @keyframes chain-pulse { 0%, 100% { opacity: 1 } 50% { opacity: .3 } }
    @keyframes chain-lit { 0%, 100% { box-shadow: 0 0 0 0 rgba(255,210,63,0); background-color: rgba(255,255,255,.07) } 20% { box-shadow: 0 0 22px 2px rgba(255,210,63,.35); background-color: rgba(255,210,63,.18) } 45% { box-shadow: 0 0 0 0 rgba(255,210,63,0); background-color: rgba(255,255,255,.07) } }
    @keyframes chain-float { 0%, 100% { transform: rotate(-2deg) translateY(0) } 50% { transform: rotate(-1deg) translateY(-8px) } }
</style>
