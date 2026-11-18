@extends('layouts.backoffice')
@section('title', $venue->name)
@section('eyebrow', '공연장')

@section('actions')
    <a href="{{ route('admin.seat-maps.create', ['venue' => $venue->id]) }}" class="btn-ink !py-2.5 text-sm">좌석 배치도 올리기</a>
@endsection

@section('content')
<div class="grid gap-6 xl:grid-cols-[1fr_340px]">
    <div class="space-y-6">
        <section>
            <h2 class="mb-3 font-bold">좌석표 <span class="text-sm font-normal text-mist">무대 배치별</span></h2>
            @if ($maps->isEmpty())
                <div class="rounded-3xl bg-white px-6 py-10 text-center ring-1 ring-ink/5">
                    <p class="font-bold">아직 좌석표가 없어요</p>
                    <p class="mt-1 text-sm text-mist">기획사가 준 좌석 배치도 이미지를 올리면 등급·구역·좌석 목록이 만들어져요.</p>
                    <a href="{{ route('admin.seat-maps.create', ['venue' => $venue->id]) }}" class="btn-ink mt-4">좌석 배치도 올리기</a>
                    <form method="POST" action="{{ route('admin.venues.temp-seat-map', $venue) }}" class="mx-auto mt-4 flex max-w-sm items-center justify-center gap-2 text-sm">
                        @csrf
                        <span class="text-mist">배치도가 없으면</span>
                        <input name="seats" type="number" min="1" value="{{ $venue->seats }}" class="field num !w-28 !py-2 text-sm" aria-label="객석 수" required>
                        <button class="btn-ghost !py-2 text-sm">석 임시 좌석표</button>
                    </form>
                </div>
            @else
                <ul class="grid gap-4 md:grid-cols-2">
                    @foreach ($maps as $m)
                        <li data-href="{{ route('admin.seat-maps.edit', $m) }}" class="cursor-pointer overflow-hidden rounded-3xl bg-white ring-1 ring-ink/5 transition hover:ring-stick/40">
                            @if ($m->isPlan())
                                @include('admin.partials.plan-thumb', ['m' => $m, 'class' => 'max-h-[300px]'])
                            @else
                                <div class="bg-ink">
                                    <div class="relative w-full overflow-hidden" style="{{ $m->frameStyle() }}">
                                        <div style="{{ $m->innerStyle() }}"><img src="{{ $m->imageUrl() }}" alt="" class="block w-full" loading="lazy"></div>
                                    </div>
                                </div>
                            @endif
                            <div class="p-4">
                                <h3 class="truncate font-black"><a href="{{ route('admin.seat-maps.edit', $m) }}">{{ $m->name }}</a></h3>
                                <p class="mt-2 flex flex-wrap gap-1.5 text-xs">
                                    <span class="chip">구역 <b class="num ml-1">{{ $m->sections->count() }}</b></span>
                                    <span class="chip">좌석 <b class="num ml-1">{{ number_format($m->totalSeats()) }}</b></span>
                                    <span class="chip">공연 <b class="num ml-1">{{ $m->events_count }}</b></span>
                                    @if ($m->isPlan())<span class="chip !bg-ink !text-white">도면</span>@endif
                                    @if ($m->read_by === 'temp')<span class="chip">임시</span>@endif
                                    @php($gap = app(\App\Services\SeatMap\SeatMapService::class)->seatGapNote($m->setRelation('venueRecord', $venue)))
                                    @if ($gap)<span class="chip !bg-signal/10 !text-signal" title="{{ $gap }}">객석 수 확인</span>@endif
                                    @if ($m->read_by === 'ai')<span class="chip">AI 판독</span>@endif
                                </p>
                                <div class="mt-3 flex flex-wrap gap-1">
                                    @foreach ($m->tiers as $t)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-paper px-2 py-0.5 text-[11px]">
                                            <span class="size-2.5 rounded-full ring-1 ring-ink/10" style="background: {{ $t['color'] }}"></span>{{ $t['name'] }} · {{ number_format($t['price']) }}원
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section>
            <h2 class="mb-3 font-bold">이 공연장 공연</h2>
            @if ($events->isEmpty())
                <p class="rounded-3xl bg-white px-6 py-6 text-sm text-mist ring-1 ring-ink/5">아직 공연이 없어요. <a href="{{ route('admin.events.create') }}" class="row-link text-ink">공연 등록</a></p>
            @else
                <ul class="divide-y divide-ink/5 overflow-hidden rounded-3xl bg-white ring-1 ring-ink/5">
                    @foreach ($events as $e)
                        <li data-href="{{ route('admin.events.edit', $e->id) }}" class="flex cursor-pointer items-center justify-between gap-3 px-5 py-3 hover:bg-paper">
                            <div class="min-w-0">
                                <p class="truncate font-bold">{{ $e->artist }} <span class="font-normal text-mist">{{ $e->name }}</span></p>
                                <p class="text-xs text-mist">{{ $e->seatMap?->name ?? '기본 아레나 보울' }}</p>
                            </div>
                            <p class="num shrink-0 text-sm">{{ $e->starts_at->format('Y.m.d H:i') }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <aside class="space-y-4">
        {{-- 도면 편집기: 무대·구역 모양을 그려 좌석을 만든다 (배치도 이미지를 밑그림으로 깔 수 있다) --}}
        <form method="POST" action="{{ route('admin.venues.plans.store', $venue) }}" class="space-y-3 rounded-3xl bg-ink p-5 text-white">
            @csrf
            <h2 class="font-bold">도면 그리기</h2>
            <p class="text-xs text-white/70">무대·구역 모양(사각·부채꼴·다각형)을 그리고 열·좌석을 정하면, 팬 화면에서 도면 그대로 확대해 좌석을 골라요.</p>
            <input name="name" class="field !py-2.5 text-ink" placeholder="예: {{ $venue->name }} · 엔드 무대" value="{{ old('name') }}" required maxlength="80" aria-label="도면 이름">
            @error('name')<p class="text-sm text-pick">{{ $message }}</p>@enderror
            @php($traceable = $maps->filter(fn ($m) => $m->image_path))
            @if ($traceable->isNotEmpty())
                <select name="trace_id" class="field !py-2.5 text-ink" aria-label="밑그림">
                    <option value="">밑그림 없이</option>
                    @foreach ($traceable as $m)<option value="{{ $m->id }}">밑그림: {{ $m->name }}</option>@endforeach
                </select>
            @endif
            <button class="w-full rounded-full bg-pick py-2.5 text-sm font-bold text-ink">도면 편집기 열기</button>
        </form>

        <form method="POST" action="{{ route('admin.venues.update', $venue) }}" class="space-y-3 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
            @csrf @method('PUT')
            <h2 class="font-bold">공연장 정보</h2>
            <div>
                <label for="name" class="label">이름</label>
                <input id="name" name="name" class="field !py-2.5" value="{{ old('name', $venue->name) }}" required maxlength="60">
                @error('name')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="address" class="label">주소</label>
                <input id="address" name="address" class="field !py-2.5" value="{{ old('address', $venue->address) }}">
            </div>
            <div>
                <label for="note" class="label">메모 <span class="font-normal text-mist">(입장 게이트·주차 등)</span></label>
                <input id="note" name="note" class="field !py-2.5" value="{{ old('note', $venue->note) }}">
            </div>
            <button class="btn-ink w-full !py-2.5 text-sm">저장</button>
        </form>
        @if ($maps->isEmpty() && $events->isEmpty())
            <form method="POST" action="{{ route('admin.venues.destroy', $venue) }}" onsubmit="return confirm('공연장을 지울까요?')">
                @csrf @method('DELETE')
                <button class="btn-ghost w-full !py-2.5 text-sm text-signal">공연장 지우기</button>
            </form>
        @endif
        <a href="{{ route('admin.venues.index') }}" class="btn-ghost w-full !py-2.5 text-sm">공연장 목록</a>
    </aside>
</div>
@endsection
