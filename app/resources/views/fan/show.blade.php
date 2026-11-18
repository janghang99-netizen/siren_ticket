@extends('layouts.ticket')
@section('title', $p->title)
@section('genre', $p->genre)

@php
    $first = $rounds->first();
    $tierNames = ['VIP' => 'VIP석', 'R' => 'R석', 'S' => 'S석', 'A' => 'A석'];
    $tierDot = ['VIP' => '#8B5CF6', 'R' => '#3B82F6', 'S' => '#10B981', 'A' => '#F59E0B'];   // 좌석 선택 화면과 같은 등급 색
@endphp

@section('content')
<div class="tix-wrap mt-6 lg:mt-10">
    <div class="lg:grid lg:grid-cols-[minmax(0,1fr)_380px] lg:gap-10">
        {{-- ── 왼쪽: 작품 정보 ── --}}
        <div class="min-w-0">
            <nav class="text-[13px] font-semibold text-mist" aria-label="위치">
                <a href="{{ route('home') }}" class="hover:text-ink">홈</a>
                <span aria-hidden="true" class="mx-1">›</span>
                <a href="{{ route('shows.genre', $p->genre) }}" class="hover:text-ink">{{ $p->genreName() }}</a>
            </nav>

            <div class="mt-4 grid gap-6 sm:grid-cols-[220px_minmax(0,1fr)] lg:grid-cols-[260px_minmax(0,1fr)] lg:gap-8">
                <div class="mx-auto w-[220px] sm:mx-0 lg:w-[260px]">
                    @include('partials.poster', ['p' => $p, 'eager' => true])
                </div>
                <div class="min-w-0">
                    <p class="flex flex-wrap gap-1.5 text-xs font-bold">
                        <span class="rounded-full bg-paper px-2.5 py-1">{{ $p->genreName() }}</span>
                        @if ($p->badge)<span class="rounded-full bg-pick px-2.5 py-1">{{ $p->badge }}</span>@endif
                    </p>
                    <h1 class="mt-3 text-[26px] font-black leading-tight tracking-tight [word-break:keep-all] lg:text-[32px]">{{ $p->title }}</h1>
                    @if ($p->artist && ! str_contains(mb_strtoupper($p->title), mb_strtoupper($p->artist)))
                        <p class="mt-1 font-display text-sm font-bold text-ink/60">{{ $p->artist }}</p>
                    @endif

                    <dl class="mt-6 grid grid-cols-[72px_minmax(0,1fr)] gap-y-2.5 text-[15px]">
                        <dt class="text-mist">장소</dt>
                        <dd><a href="#venue" class="font-semibold underline-offset-4 hover:underline">{{ $p->venue }}</a></dd>
                        <dt class="text-mist">기간</dt>
                        <dd class="num">{{ $p->period('Y.m.d') }}</dd>
                        @if ($p->runtime_minutes)
                            <dt class="text-mist">관람시간</dt>
                            <dd>{{ $p->runtime_minutes }}분</dd>
                        @endif
                        @if ($p->age_rating)
                            <dt class="text-mist">관람연령</dt>
                            <dd>{{ $p->age_rating }}</dd>
                        @endif
                        <dt class="text-mist">가격</dt>
                        <dd>
                            @foreach ($prices as $t)
                                <span class="mr-3 inline-block whitespace-nowrap">{{ $tierNames[$t['name']] ?? $t['name'].'석' }} <b class="num">{{ number_format($t['price']) }}</b>원</span>
                            @endforeach
                        </dd>
                    </dl>

                    {{-- 예매 일정 --}}
                    <div class="mt-6 rounded-2xl bg-paper px-5 py-4">
                        <p class="text-[13px] font-semibold text-mist">일반 예매</p>
                        @if ($p->opensLater())
                            <p class="mt-0.5 text-lg font-black">{{ $first['opens'] }} 오픈</p>
                        @else
                            <p class="mt-0.5 flex items-center gap-2 text-lg font-black"><span class="h-2 w-2 rounded-full bg-[#2BB673]" aria-hidden="true"></span>지금 예매할 수 있어요</p>
                        @endif
                        <p class="mt-1 text-[13px] text-mist">1인 최대 {{ $maxPerBuyer }}매 · 회원만 예매할 수 있어요</p>
                    </div>
                </div>
            </div>

            {{-- 탭 (위에 붙어 따라온다) --}}
            <nav class="sticky top-[104px] z-30 -mx-4 mt-10 border-b border-ink/10 bg-white/95 px-4 backdrop-blur lg:top-[116px] lg:mx-0 lg:px-0" aria-label="상세 메뉴">
                <ul class="flex gap-1 overflow-x-auto text-[15px] font-semibold [scrollbar-width:none]">
                    @foreach (['info' => '상세정보', 'price' => '가격', 'guide' => '이용안내', 'refund' => '취소/환불', 'venue' => '장소'] as $id => $label)
                        <li><a href="#{{ $id }}" class="block whitespace-nowrap border-b-2 border-transparent px-3 py-3.5 text-ink/55 hover:border-ink hover:text-ink">{{ $label }}</a></li>
                    @endforeach
                </ul>
            </nav>

            <section id="info" class="scroll-mt-44 pt-10">
                <h2 class="text-xl font-black">상세정보</h2>
                @if ($p->description)
                    <div class="mt-4 whitespace-pre-line text-[15px] leading-relaxed text-ink/80">{{ $p->description }}</div>
                @else
                    @if ($p->bannerUrl())<img src="{{ $p->bannerUrl() }}" alt="{{ $p->title }}" class="mt-4 w-full rounded-2xl" loading="lazy">@endif
                    <p class="mt-4 text-[15px] leading-relaxed text-ink/70">{{ $p->title }} — {{ $p->venue }}, {{ $p->period('Y.m.d') }}. 공연 상세 내용은 준비 중이에요.</p>
                @endif
            </section>

            <section id="price" class="scroll-mt-44 pt-14">
                <h2 class="text-xl font-black">가격</h2>
                <ul class="mt-4 divide-y divide-ink/[.06] rounded-2xl ring-1 ring-ink/[.08]">
                    @foreach ($prices as $t)
                        <li class="flex items-center justify-between px-5 py-4">
                            <span class="flex items-center gap-2.5 font-semibold"><span class="h-3 w-3 rounded-full" style="background: {{ $tierDot[$t['name']] ?? '#8A84A3' }}"></span>{{ $tierNames[$t['name']] ?? $t['name'].'석' }}</span>
                            <span class="num text-lg font-bold">{{ number_format($t['price']) }}<span class="font-sans text-sm font-semibold">원</span>@if ($t['max'] !== $t['price']) <span class="text-sm text-mist">~ {{ number_format($t['max']) }}원</span>@endif</span>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-[13px] text-mist">예매 수수료는 받지 않아요.</p>
            </section>

            <section id="guide" class="scroll-mt-44 pt-14">
                <h2 class="text-xl font-black">이용안내</h2>
                <ul class="mt-4 space-y-2.5 text-[15px] leading-relaxed text-ink/80">
                    <li class="flex gap-2"><span class="text-mist">·</span><span>공연 일시: {{ $rounds->pluck('label')->join(', ') }}</span></li>
                    @if ($doorsBefore)<li class="flex gap-2"><span class="text-mist">·</span><span>입장은 공연 시작 {{ $doorsBefore }}분 전부터예요.</span></li>@endif
                    <li class="flex gap-2"><span class="text-mist">·</span><span>티켓은 ‘내 티켓’의 입장 코드(QR)로 입장해요. 화면 캡처로는 입장이 안 될 수 있어요.</span></li>
                    <li class="flex gap-2"><span class="text-mist">·</span><span>1인 최대 {{ $maxPerBuyer }}매까지 예매할 수 있어요.</span></li>
                    <li class="flex gap-2"><span class="text-mist">·</span><span>공연 전·인터미션에 공연장 매장 음료·간식·MD를 <a href="{{ $storesUrl }}" class="font-semibold text-ink underline underline-offset-4">미리 주문</a>할 수 있어요.</span></li>
                </ul>
            </section>

            <section id="refund" class="scroll-mt-44 pt-14">
                <h2 class="text-xl font-black">취소/환불 규정</h2>
                <p class="mt-4 text-[15px] text-ink/80">관람일 기준으로 아래 취소 수수료가 붙어요. 공연이 취소되면 전액 환불돼요.</p>
                <table class="mt-4 w-full overflow-hidden rounded-2xl text-left text-[15px] ring-1 ring-ink/[.08]">
                    <thead class="bg-paper text-[13px] text-mist"><tr><th class="px-5 py-3 font-semibold">취소 시점</th><th class="px-5 py-3 font-semibold">취소 수수료</th></tr></thead>
                    <tbody class="divide-y divide-ink/[.06]">
                        @foreach ([['관람일 10일 전까지', '없음'], ['관람일 9일 ~ 7일 전', '티켓 금액의 10%'], ['관람일 6일 ~ 3일 전', '티켓 금액의 20%'], ['관람일 2일 ~ 1일 전', '티켓 금액의 30%'], ['관람일 당일', '취소할 수 없어요']] as [$when, $fee])
                            <tr><td class="px-5 py-3">{{ $when }}</td><td class="px-5 py-3 font-semibold">{{ $fee }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
                <a href="{{ route('policy', 'refund') }}" class="tix-more mt-3">취소·환불 규정 전체 보기 <span aria-hidden="true">›</span></a>
            </section>

            <section id="venue" class="scroll-mt-44 pt-14">
                <h2 class="text-xl font-black">장소</h2>
                <div class="mt-4 flex items-start justify-between gap-4 rounded-2xl bg-paper px-5 py-5">
                    <div>
                        <p class="text-lg font-bold">{{ $p->venue }}</p>
                        @if ($venue?->address)<p class="mt-1 text-[15px] text-ink/70">{{ $venue->address }}</p>@endif
                    </div>
                    @if ($venue?->address)
                        <a href="https://map.naver.com/p/search/{{ rawurlencode($venue->address) }}" target="_blank" rel="noopener" class="shrink-0 rounded-full bg-white px-4 py-2 text-sm font-semibold ring-1 ring-ink/10 hover:ring-ink">지도 보기</a>
                    @endif
                </div>
            </section>
        </div>

        {{-- ── 오른쪽: 예매 패널 (넓은 화면은 따라온다) ── --}}
        <aside id="booking" class="mt-12 scroll-mt-32 lg:mt-0">
            <div class="rounded-[24px] bg-white p-5 shadow-[0_20px_60px_-30px_rgba(23,19,43,.35)] ring-1 ring-ink/[.08] lg:sticky lg:top-[136px]"
                 x-data="bookingPanel(@js($rounds))">
                {{-- 달력 --}}
                <div class="flex items-center justify-between">
                    <button type="button" @click="move(-1)" x-bind:disabled="mi === 0" class="grid h-9 w-9 place-items-center rounded-full hover:bg-paper disabled:opacity-25" aria-label="이전 달">
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <p class="num text-lg font-bold" x-text="months[mi].replace('-', '.')"></p>
                    <button type="button" @click="move(1)" x-bind:disabled="mi === months.length - 1" class="grid h-9 w-9 place-items-center rounded-full hover:bg-paper disabled:opacity-25" aria-label="다음 달">
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                    </button>
                </div>
                <div class="mt-3 grid grid-cols-7 text-center text-[13px] font-semibold text-mist">
                    @foreach (['일', '월', '화', '수', '목', '금', '토'] as $i => $d)<span @class(['py-1', 'text-signal/70' => $i === 0])>{{ $d }}</span>@endforeach
                </div>
                <div class="grid grid-cols-7 gap-y-1 text-center" aria-label="공연 날짜">
                    <template x-for="(c, k) in cells" :key="k">
                        <div class="grid h-11 place-items-center">
                            <template x-if="c">
                                <button type="button" x-bind:disabled="! c.has" @click="pick(c.date)"
                                        class="num relative grid h-10 w-10 place-items-center rounded-full text-[15px] transition"
                                        x-bind:class="c.date === day ? 'bg-ink font-bold text-white' : (c.has ? 'font-bold text-ink hover:bg-paper' : 'text-ink/20')"
                                        x-bind:aria-pressed="c.date === day" x-bind:aria-label="c.date">
                                    <span x-text="c.d"></span>
                                    <span x-show="c.has && c.date !== day" class="absolute bottom-1 h-1 w-1 rounded-full bg-pick"></span>
                                </button>
                            </template>
                        </div>
                    </template>
                </div>

                {{-- 회차 --}}
                <div class="mt-4 space-y-2 border-t border-ink/[.06] pt-4">
                    <template x-for="r in roundsOn(day)" :key="r.id">
                        <button type="button" @click="sel = r.id" class="w-full rounded-2xl px-4 py-3.5 text-left ring-1 transition"
                                x-bind:class="sel === r.id ? 'ring-2 ring-ink' : 'ring-ink/10 hover:ring-ink/30'">
                            <span class="num block text-lg font-bold" x-text="r.time"></span>
                            <span class="mt-0.5 flex flex-wrap gap-x-2 text-[13px] text-mist">
                                <template x-for="t in r.tiers" :key="t.name">
                                    <span><span x-text="t.name + '석'"></span> <b class="num text-ink" x-text="t.left.toLocaleString()"></b></span>
                                </template>
                            </span>
                        </button>
                    </template>
                </div>

                {{-- 예매 --}}
                <div class="mt-4">
                    <a x-show="cur && cur.open && cur.left > 0" x-bind:href="cur && cur.url" class="flex h-14 w-full items-center justify-center rounded-2xl bg-ink text-[17px] font-bold text-white transition hover:opacity-90">예매하기</a>
                    <p x-show="cur && ! cur.open" x-cloak class="flex h-14 w-full items-center justify-center rounded-2xl bg-paper text-[15px] font-bold text-ink/70" x-text="cur && (cur.opens + ' 예매 오픈')"></p>
                    <p x-show="cur && cur.open && cur.left === 0" x-cloak class="flex h-14 w-full items-center justify-center rounded-2xl bg-paper text-[15px] font-bold text-signal">매진</p>
                </div>
            </div>
        </aside>
    </div>
</div>

{{-- 휴대폰: 아래에 예매 버튼 (예매 패널로 내려간다) --}}
<div class="fixed inset-x-0 bottom-[76px] z-30 px-4 lg:hidden">
    <a href="#booking" class="mx-auto flex max-w-md items-center justify-center rounded-2xl bg-pick py-3.5 text-[16px] font-bold text-ink shadow-lg shadow-ink/20">날짜 고르고 예매하기</a>
</div>

<script>
    // 예매 패널: 달력(공연 있는 날만 고를 수 있음) → 회차 → 등급별 남은 좌석
    window.bookingPanel = (rounds) => ({
        rounds,
        months: [...new Set(rounds.map(r => r.date.slice(0, 7)))],
        mi: 0,
        day: rounds[0].date,
        sel: rounds[0].id,
        get cur() { return this.rounds.find(r => r.id === this.sel) || null },
        get cells() {
            const [y, m] = this.months[this.mi].split('-').map(Number);
            const startDow = new Date(y, m - 1, 1).getDay(), last = new Date(y, m, 0).getDate();
            const has = new Set(this.rounds.map(r => r.date));
            const out = Array(startDow).fill(null);
            for (let d = 1; d <= last; d++) {
                const date = `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                out.push({ d, date, has: has.has(date) });
            }
            return out;
        },
        roundsOn(date) { return this.rounds.filter(r => r.date === date) },
        pick(date) { this.day = date; this.sel = this.roundsOn(date)[0].id },
        move(step) {
            this.mi = Math.min(this.months.length - 1, Math.max(0, this.mi + step));
            const inMonth = this.rounds.find(r => r.date.startsWith(this.months[this.mi]));
            if (inMonth) this.pick(inMonth.date);
        },
    });
</script>
@endsection
