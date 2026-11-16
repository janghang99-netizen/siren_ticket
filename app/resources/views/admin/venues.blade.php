@extends('layouts.backoffice')
@section('title', '공연장')
@section('eyebrow', '좌석티켓')

@section('content')
    <p class="mb-5 max-w-3xl text-sm text-mist">
        공연장마다 기획사가 준 좌석 배치도 이미지를 올려 좌석표를 만들어요. 같은 공연장도 무대 배치(가로·세로)가 다르면 좌석표를 따로 두고, 공연을 등록할 때 공연장과 좌석표를 골라요.
    </p>

    <details class="mb-6 max-w-3xl rounded-3xl bg-white p-5 ring-1 ring-ink/5" @if ($errors->any() || $venues->isEmpty()) open @endif>
        <summary class="cursor-pointer font-bold">＋ 공연장 추가</summary>
        <form method="POST" action="{{ route('admin.venues.store') }}" class="mt-4 grid gap-3 sm:grid-cols-[1fr_1.4fr_auto] sm:items-end">
            @csrf
            <div>
                <label for="name" class="label">공연장 이름</label>
                <input id="name" name="name" class="field !py-2.5" value="{{ old('name') }}" placeholder="예: 고양종합운동장" required maxlength="60">
                @error('name')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="address" class="label">주소 <span class="font-normal text-mist">(선택)</span></label>
                <input id="address" name="address" class="field !py-2.5" value="{{ old('address') }}" placeholder="예: 경기 고양시 일산서구 중앙로 1601">
            </div>
            <button class="btn-ink !py-2.5">추가</button>
        </form>
    </details>

    @if ($venues->isEmpty())
        <p class="rounded-3xl bg-white px-6 py-12 text-center text-mist ring-1 ring-ink/5">아직 공연장이 없어요. 위에서 공연장을 추가하세요.</p>
    @else
        <ul class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($venues as $v)
                @php($first = $v->seatMaps->first())
                <li data-href="{{ route('admin.venues.show', $v) }}" class="cursor-pointer overflow-hidden rounded-3xl bg-white ring-1 ring-ink/5 transition hover:ring-stick/40">
                    <div class="bg-ink">
                        @if ($first)
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
