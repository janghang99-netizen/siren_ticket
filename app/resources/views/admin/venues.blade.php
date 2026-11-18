@extends('layouts.backoffice')
@section('title', '공연장')
@section('eyebrow', '좌석티켓')

@section('content')
    <p class="mb-5 max-w-3xl text-sm text-mist">
        공연장마다 기획사가 준 좌석 배치도 이미지를 올려 좌석표를 만들어요. 같은 공연장도 무대 배치(가로·세로)가 다르면 좌석표를 따로 두고, 공연을 등록할 때 공연장과 좌석표를 골라요.
    </p>

    {{-- 공연장 추가: 전국 공연장 DB 에서 검색해 불러오기 (없으면 직접 입력) --}}
    <section class="mb-6 max-w-3xl rounded-3xl bg-white p-5 ring-1 ring-ink/5"
             x-data="{
                q: '', results: [], open: false, busy: false, pick: null, hall: '',
                name: @js(old('name', '')), address: @js(old('address', '')), seats: @js(old('seats', '')), catalogId: @js(old('catalog_id', '')),
                async search() {
                    const q = this.q.trim(); if (!q) { this.results = []; return }
                    this.busy = true;
                    try { this.results = await (await fetch(@js(route('admin.venues.catalog')) + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } })).json() } catch (e) { this.results = [] }
                    this.busy = false; this.open = true;
                },
                async choose(r) {
                    this.open = false; this.busy = true;
                    try { r = await (await fetch(@js(route('admin.venues.catalog.show', 0)).replace(/0$/, r.id), { headers: { Accept: 'application/json' } })).json() } catch (e) {}
                    this.busy = false; this.pick = r; this.hall = ''; this.catalogId = r.id;
                    this.name = r.name.slice(0, 60); this.address = r.address || r.region; this.seats = r.seats || '';
                },
                useHall() {
                    const h = (this.pick?.halls || []).find(h => h.id === this.hall);
                    this.name = (this.pick.name + (h ? ' ' + h.name : '')).slice(0, 60); this.seats = h?.seats || this.pick.seats || '';
                },
             }">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-bold">＋ 공연장 추가</h2>
            <p class="text-xs text-mist">전국 공연장 DB <b class="num">{{ number_format($catalogCount) }}</b>곳
                {{ $kopisCount ? '(KOPIS '.number_format($kopisCount).'곳 포함)' : '(기본 목록 — KOPIS 키를 넣으면 전국 공연시설을 받아 와요)' }}</p>
        </div>
        <div class="relative mt-3">
            <label for="venue-search" class="sr-only">공연장 검색</label>
            <input id="venue-search" x-model="q" @input.debounce.300ms="search()" @focus="results.length && (open = true)" @keydown.escape="open = false"
                   class="field" placeholder="공연장 이름이나 지역으로 검색 (예: 체조, 고양, 부산)" autocomplete="off">
            <ul x-show="open" x-cloak @click.outside="open = false" class="absolute z-30 mt-1 max-h-80 w-full overflow-auto rounded-2xl bg-white py-1 text-sm shadow-xl ring-1 ring-ink/10">
                <template x-for="r in results" :key="r.id">
                    <li><button type="button" class="flex w-full items-start justify-between gap-3 px-4 py-2.5 text-left hover:bg-paper" @click="choose(r)">
                        <span class="min-w-0"><b class="block truncate" x-text="r.name"></b><span class="block truncate text-xs text-mist" x-text="r.address || r.region"></span></span>
                        <span class="shrink-0 text-[11px] text-mist" x-text="r.source === 'kopis' ? 'KOPIS' : '기본'"></span>
                    </button></li>
                </template>
                <li x-show="!results.length" class="px-4 py-3 text-mist">검색 결과가 없어요. 아래에 직접 입력하세요.</li>
            </ul>
            <p x-show="busy" class="mt-1 text-xs text-mist">불러오는 중…</p>
        </div>

        <form method="POST" action="{{ route('admin.venues.store') }}" class="mt-4 space-y-3">
            @csrf
            <input type="hidden" name="catalog_id" :value="catalogId">
            <div x-show="pick && pick.halls && pick.halls.length" x-cloak>
                <label for="hall" class="label">공연장(홀) <span class="font-normal text-mist">이 시설 안의 어느 공연장인지</span></label>
                <select id="hall" x-model="hall" @change="useHall()" class="field !py-2.5">
                    <option value="">시설 전체</option>
                    <template x-for="h in pick?.halls || []" :key="h.id"><option :value="h.id" x-text="h.name + (h.seats ? ' · ' + won(h.seats) + '석' : '')"></option></template>
                </select>
            </div>
            <div class="grid gap-3 sm:grid-cols-[1fr_1.4fr_120px_auto] sm:items-end">
                <div>
                    <label for="name" class="label">공연장 이름</label>
                    <input id="name" name="name" x-model="name" class="field !py-2.5" placeholder="예: 고양종합운동장" required maxlength="60">
                    @error('name')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="address" class="label">주소</label>
                    <input id="address" name="address" x-model="address" class="field !py-2.5" maxlength="255">
                </div>
                <div>
                    <label for="seats" class="label">객석 수</label>
                    <input id="seats" name="seats" type="number" min="0" x-model="seats" class="field num !py-2.5">
                </div>
                <button class="btn-ink !py-2.5">추가</button>
            </div>
            <p x-show="catalogId" x-cloak class="text-xs text-mist">전국 공연장 DB 에서 불러왔어요. 이름은 고쳐도 돼요.
                <button type="button" class="underline" @click="catalogId = ''; pick = null">연결 끊고 직접 입력</button></p>
        </form>
        @unless ($kopisReady)
            <p class="mt-4 rounded-2xl bg-paper px-4 py-3 text-xs text-mist">전국 공연시설(KOPIS)을 받으려면 kopis.or.kr 에서 서비스 키를 발급받아 서버 환경 변수 <code>KOPIS_API_KEY</code> 에 넣고
                <code>php artisan venues:import-kopis</code> 를 한 번 실행하세요. 이후 매달 1일 자동으로 새로 받아요.</p>
        @endunless
    </section>

    @if ($venues->isEmpty())
        <p class="rounded-3xl bg-white px-6 py-12 text-center text-mist ring-1 ring-ink/5">아직 공연장이 없어요. 위에서 공연장을 추가하세요.</p>
    @else
        <ul class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($venues as $v)
                @php($first = $v->seatMaps->first())
                <li data-href="{{ route('admin.venues.show', $v) }}" class="cursor-pointer overflow-hidden rounded-3xl bg-white ring-1 ring-ink/5 transition hover:ring-stick/40">
                    <div class="bg-ink">
                        @if ($first?->isPlan())
                            @include('admin.partials.plan-thumb', ['m' => $first, 'class' => 'max-h-[220px]'])
                        @elseif ($first)
                            <div class="relative w-full overflow-hidden" style="{{ $first->frameStyle() }}; max-height: 220px">
                                <div style="{{ $first->innerStyle() }}"><img src="{{ $first->imageUrl() }}" alt="" class="block w-full" loading="lazy"></div>
                            </div>
                        @else
                            <div class="grid h-36 place-items-center text-sm text-paper/60">좌석표 없음</div>
                        @endif
                    </div>
                    <div class="p-4">
                        <h2 class="truncate text-lg font-black"><a href="{{ route('admin.venues.show', $v) }}">{{ $v->name }}</a></h2>
                        <p class="truncate text-xs text-mist">{{ $v->address ?: '주소 없음' }}</p>
                        <p class="mt-3 flex flex-wrap gap-1.5 text-xs">
                            <span class="chip">좌석표 <b class="num ml-1">{{ $v->seatMaps->count() }}</b></span>
                            <span class="chip">공연 <b class="num ml-1">{{ $v->events_count }}</b></span>
                            @if ($v->next_event_at)
                                <span class="chip">다음 공연 <b class="num ml-1">{{ \Illuminate\Support\Carbon::parse($v->next_event_at)->format('m.d') }}</b></span>
                            @endif
                        </p>
                        @if ($v->seatMaps->isNotEmpty())
                            <ul class="mt-3 space-y-1 text-sm">
                                @foreach ($v->seatMaps as $m)
                                    <li class="flex justify-between gap-2"><span class="truncate">{{ $m->name }}</span>
                                        <span class="num shrink-0 text-xs text-mist">{{ $m->sections->count() }}구역 · {{ number_format($m->totalSeats()) }}석</span></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
