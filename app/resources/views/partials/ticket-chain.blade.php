{{-- 내 티켓의 블록체인 기록 — 발행 → 입장 → (취소면) 소각, 단계마다 탐색기 링크. $tk = TicketToken --}}
@php
    $pending = $tk->onChain() && ! $tk->mint_tx;
    $steps = [
        ['발행', $pending ? '좌석 토큰을 블록체인에 보내는 중이에요' : '좌석 토큰이 내 지갑에 발행됐어요', $tk->minted_at, $tk->mint_tx, true],
        ['입장', '검표하면 ‘사용됨’이 한 번만 기록돼요', $tk->used_at, $tk->used_tx ?? null, $tk->status === 'used'],
    ];
    if ($tk->status === 'burned') {
        $steps[] = ['소각', '예매를 취소해 토큰이 없어졌어요', $tk->burned_at, $tk->burn_tx, true];
    }
@endphp
<div class="theme-keep mt-4 overflow-hidden rounded-2xl text-white" style="background: linear-gradient(150deg, #1B1733 0%, #0E0C15 100%)">
    <div class="flex items-center justify-between gap-2 px-4 pt-4">
        <p class="flex items-center gap-1.5 text-[13px] font-bold">
            <svg viewBox="0 0 24 24" class="h-4 w-4 text-pick" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6Z"/><path d="m9 12 2 2 4-4"/></svg>
            블록체인 인증 티켓
        </p>
        <span class="rounded-full bg-white/10 px-2 py-0.5 text-[11px] font-semibold text-white/80">{{ $tk->networkLabel() }}</span>
    </div>

    <div class="mt-3 flex items-end justify-between gap-3 px-4">
        <div class="min-w-0">
            <p class="text-[11px] font-bold tracking-wider text-white/45">NFT 티켓</p>
            <p class="num truncate text-2xl font-black" title="#{{ $tk->token_id }}">{{ $tk->displayId() }}</p>
        </div>
        <p class="flex shrink-0 flex-wrap justify-end gap-1 text-[11px] font-bold">
            <span class="rounded-md bg-pick px-1.5 py-0.5 text-ink">{{ $tk->statusLabel() }}</span>
            <span class="inline-flex items-center gap-1 rounded-md bg-white/10 px-1.5 py-0.5">
                <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2.6"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>양도 불가
            </span>
        </p>
    </div>

    {{-- 기록 흐름 --}}
    <ol class="mx-4 mt-4 border-l border-white/15 pl-4 text-[12px]">
        @foreach ($steps as [$label, $desc, $at, $tx, $done])
            <li class="relative pb-3 last:pb-0">
                <span @class(['absolute -left-[21px] top-0.5 h-2.5 w-2.5 rounded-full ring-4 ring-[#15122A]', 'bg-pick' => $done && $at, 'bg-white/25' => ! ($done && $at)])></span>
                <p class="flex items-center justify-between gap-2">
                    <span class="font-bold {{ $done && $at ? '' : 'text-white/50' }}">{{ $label }}
                        @if ($at)<span class="num ml-1 font-normal text-white/50">{{ $at->format('Y.m.d H:i') }}</span>@endif
                    </span>
                    @if ($tx && $tk->explorerUrl($tx))
                        <a href="{{ $tk->explorerUrl($tx) }}" target="_blank" rel="noopener" class="shrink-0 font-semibold text-pick underline-offset-2 hover:underline">기록 보기 ↗</a>
                    @elseif ($done && $at && $tk->onChain())
                        <span class="shrink-0 text-white/45">기록 중…</span>
                    @endif
                </p>
                <p class="text-white/45">{{ $desc }}</p>
            </li>
        @endforeach
    </ol>

    {{-- 지갑 · 탐색기 --}}
    <div class="mt-4 border-t border-white/10 bg-white/[.04] px-4 py-3 text-[12px]" x-data="{ copied: false }">
        <p class="flex items-center justify-between gap-2">
            <span class="text-white/50">내 지갑</span>
            <button type="button" class="num inline-flex items-center gap-1 font-semibold hover:text-pick"
                    @click="navigator.clipboard?.writeText(@js($tk->owner_address)); copied = true; setTimeout(() => copied = false, 1500)"
                    aria-label="지갑 주소 복사">
                {{ \App\Models\TicketToken::short($tk->owner_address) }}
                <span x-text="copied ? '복사됨' : '복사'" class="rounded bg-white/10 px-1 text-[10px] font-bold"></span>
            </button>
        </p>
        @if ($tk->onChain())
            <div class="mt-2.5 grid grid-cols-2 gap-1.5">
                <a href="{{ $tk->explorerUrl() }}" target="_blank" rel="noopener" class="rounded-lg bg-white/10 py-2 text-center font-bold hover:bg-white/15">NFT 보기 ↗</a>
                <a href="{{ $tk->explorerAddressUrl() }}" target="_blank" rel="noopener" class="rounded-lg bg-white/10 py-2 text-center font-bold hover:bg-white/15">내 지갑 기록 ↗</a>
            </div>
            <p class="mt-2 text-[11px] text-white/40">
                @if ($pending) 블록체인 기록을 보내는 중이에요. 잠시 뒤 다시 열어 보세요.
                @else 다담다가 아닌 카이아 공식 탐색기(Kaiascan)에서 누구나 같은 기록을 확인할 수 있어요.
                @endif
            </p>
        @else
            <p class="mt-2 text-[11px] text-white/45">이 티켓은 다담다 서버에 기록돼요 (블록체인 기록을 쓰지 않는 환경). 위조 방지·1회 입장은 똑같이 적용돼요.</p>
        @endif
        <a href="{{ route('about.blockchain') }}" class="mt-2 inline-block text-[11px] font-semibold text-white/60 underline underline-offset-2 hover:text-white">블록체인 티켓이란?</a>
    </div>
</div>
