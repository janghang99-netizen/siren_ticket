@extends('layouts.backoffice')
@section('title', '좌석표 올리기')
@section('eyebrow', '공연장 좌석표')

@section('content')
<form method="POST" action="{{ route('admin.seat-maps.store') }}" enctype="multipart/form-data"
      x-data="{ preview: null, busy: false }" @submit="busy = true"
      class="grid max-w-5xl gap-6 lg:grid-cols-[1fr_320px]">
    @csrf
    <section class="space-y-4 rounded-3xl bg-white p-6 ring-1 ring-ink/5">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="venue_id" class="label">공연장 <a href="{{ route('admin.venues.index') }}" class="font-normal text-stick">＋ 공연장 추가</a></label>
                <select id="venue_id" name="venue_id" class="field" required>
                    <option value="">공연장을 고르세요</option>
                    @foreach ($venues as $v)
                        <option value="{{ $v->id }}" @selected((int) old('venue_id', $venueId) === $v->id)>{{ $v->name }}</option>
                    @endforeach
                </select>
                @error('venue_id')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="name" class="label">좌석표 이름 <span class="font-normal text-mist">(배치 구분)</span></label>
                <input id="name" name="name" class="field" value="{{ old('name') }}" placeholder="예: 가로 무대 / 세로 무대 / 360도" required>
                @error('name')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
        </div>

        <div>
            <label for="image" class="label">좌석 배치도 이미지 <span class="font-normal text-mist">(JPG·PNG·WEBP, 10MB 까지)</span></label>
            <label for="image" class="mt-1 grid min-h-56 cursor-pointer place-items-center overflow-hidden rounded-2xl border-2 border-dashed border-ink/15 bg-paper text-center transition hover:border-stick/50">
                <img x-show="preview" :src="preview" alt="올린 배치도 미리보기" class="max-h-[420px] w-full object-contain" x-cloak>
                <span x-show="!preview" class="p-6 text-sm text-mist">눌러서 이미지를 고르세요<br><span class="text-xs">구역 이름과 범례(등급·색·가격)가 잘 보이는 이미지일수록 정확해요</span></span>
            </label>
            <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" required
                   @change="const f = $event.target.files[0]; preview = f ? URL.createObjectURL(f) : null">
            @error('image')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        </div>
    </section>

    <aside class="space-y-4">
        <div class="rounded-3xl bg-white p-5 text-sm ring-1 ring-ink/5">
            <p class="font-bold">객석 목록 만드는 방법</p>
            @if ($aiReady)
                <label class="mt-3 flex items-start gap-2">
                    <input type="checkbox" name="use_ai" value="1" class="mt-0.5" checked>
                    <span><b>AI 로 읽기</b> — 범례에서 등급·가격·좌석 수를, 배치도에서 구역 이름·위치를 읽어 객석 목록을 만들고, 제목·범례·요약표를 뺀 배치도 부분만 팬에게 보이게 잘라요. 1~2분 걸릴 수 있어요.</span>
                </label>
            @else
                <p class="mt-2 rounded-2xl bg-paper px-3 py-2 text-xs text-mist">AI 판독을 쓰려면 서버에 <code>ANTHROPIC_API_KEY</code> 를 넣어 주세요. 지금은 다음 화면에서 이미지 위에 구역을 직접 그려요.</p>
            @endif
            <p class="mt-3 text-xs text-mist">다음 화면에서 구역 박스를 끌어 위치를 맞추고, 구역마다 열 수 · 열당 좌석 수를 정하면 좌석 목록이 만들어져요.</p>
        </div>
        <button class="btn-ink w-full" :disabled="busy">
            <span x-text="busy ? '올리는 중… (AI 판독은 1~2분)' : '올리고 구역 만들기'">올리고 구역 만들기</span>
        </button>
        <a href="{{ $venueId ? route('admin.venues.show', $venueId) : route('admin.venues.index') }}" class="btn-ghost w-full">돌아가기</a>
    </aside>
</form>
@endsection
