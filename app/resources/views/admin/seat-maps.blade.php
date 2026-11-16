@extends('layouts.backoffice')
@section('title', '공연장 좌석표')
@section('eyebrow', '좌석티켓')

@section('actions')
    <a href="{{ route('admin.seat-maps.create') }}" class="btn-ink !py-2.5 text-sm">좌석표 올리기</a>
@endsection

@section('content')
    <p class="mb-5 max-w-3xl text-sm text-mist">
        기획사가 준 좌석 배치도 이미지를 올리면 등급·구역·좌석 목록이 만들어져요. 같은 공연장이라도 무대 배치(가로·세로)가 다르면 좌석표를 따로 만드세요.
        공연을 등록할 때 좌석표를 고르면 팬이 그 배치도에서 구역과 좌석을 직접 골라 예매해요.
    </p>

    @if ($maps->isEmpty())
        <div class="rounded-3xl bg-white px-6 py-12 text-center ring-1 ring-ink/5">
            <p class="text-lg font-bold">아직 좌석표가 없어요</p>
            <p class="mt-1 text-sm text-mist">좌석 배치도 이미지(JPG·PNG·WEBP)를 올려 시작하세요.</p>
            <a href="{{ route('admin.seat-maps.create') }}" class="btn-ink mt-5">첫 좌석표 올리기</a>
        </div>
    @else
        <ul class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($maps as $m)
                <li data-href="{{ route('admin.seat-maps.edit', $m) }}" class="cursor-pointer overflow-hidden rounded-3xl bg-white ring-1 ring-ink/5 transition hover:ring-stick/40">
                    <div class="aspect-[16/9] overflow-hidden bg-ink">
                        <img src="{{ $m->imageUrl() }}" alt="" class="size-full object-contain" loading="lazy">
                    </div>
                    <div class="p-4">
                        <p class="text-xs text-mist">{{ $m->venue }}</p>
                        <h2 class="truncate text-lg font-black"><a href="{{ route('admin.seat-maps.edit', $m) }}">{{ $m->name }}</a></h2>
                        <p class="mt-2 flex flex-wrap gap-1.5 text-xs">
                            <span class="chip">구역 <b class="num ml-1">{{ $m->sections->count() }}</b></span>
                            <span class="chip">좌석 <b class="num ml-1">{{ number_format($m->totalSeats()) }}</b></span>
                            <span class="chip">공연 <b class="num ml-1">{{ $m->events_count }}</b></span>
                            @if ($m->read_by === 'ai')<span class="chip">AI 판독</span>@endif
                        </p>
                        <div class="mt-3 flex flex-wrap gap-1">
                            @foreach ($m->tiers as $t)
                                <span class="inline-flex items-center gap-1 rounded-full bg-paper px-2 py-0.5 text-[11px]">
                                    <span class="size-2.5 rounded-full ring-1 ring-ink/10" style="background: {{ $t['color'] }}"></span>{{ $t['name'] }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
