@extends('layouts.backoffice')
@section('title', '번역')
@section('eyebrow', '구글 번역 위젯 · 상품명 AI 사전 · 오역 교정')

@section('content')
    @error('translate')
        <p role="alert" class="mb-4 rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal">{{ $message }}</p>
    @enderror
    @if (session('sample'))
        <div class="mb-4 rounded-2xl bg-white px-4 py-3 text-sm ring-1 ring-ink/5">
            <p class="text-xs text-mist">연결 테스트 결과 (저장하지 않음)</p>
            <ul class="mt-1 space-y-0.5">
                @foreach (session('sample') as $ko => $tr)
                    <li>{{ $ko }} → <b>{{ $tr }}</b></li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="mb-6 rounded-3xl bg-white p-6 text-sm leading-relaxed ring-1 ring-ink/5">
        <h2 class="font-bold">어떻게 번역하나요</h2>
        <ol class="mt-2 list-decimal space-y-1 pl-5 text-ink/80">
            <li>팬 화면 전체는 <b>구글 번역 위젯</b>이 English · 日本語 · 中文 으로 바꿔요. 첫 방문 때 브라우저 언어를 보고 자동으로 골라요.</li>
            <li>상품명은 구글이 자주 틀려서(예: 가소사 → 휘발유) <b>Claude</b>로 미리 번역한 사전을 써요. 사전에 있는 상품명은 구글이 건드리지 않아요.</li>
            <li>상품명을 바꾸면 그 상품의 번역은 바로 빠지고 다시 <b>대기</b>가 돼요. 매시 자동 번역(키가 있을 때)이나 아래 버튼으로 채워요.</li>
            <li>구글이 틀리게 옮긴 말은 <b>교정 사전</b>에 넣으면 화면에서 바로 고쳐져요.</li>
            <li>중국 본토에서는 구글 번역이 막혀 있어요. 그때도 상품명 사전과 교정 사전은 적용돼요.</li>
        </ol>
    </section>

    <div class="mb-6 grid gap-3 sm:grid-cols-4">
        <div @class(['rounded-3xl p-5', 'bg-ink text-paper' => $ready, 'bg-signal text-white' => ! $ready])>
            <p class="text-xs opacity-70">Claude API 키</p>
            <p class="mt-1 text-xl font-black">{{ $ready ? '설정됨' : '없음' }}</p>
            <p class="text-xs opacity-70">{{ $ready ? $model : 'ANTHROPIC_API_KEY 환경 변수' }}</p>
        </div>
        @foreach ($langs as $code => $label)
            @php($s = $status[$code])
            <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/5">
                <p class="text-xs text-mist">{{ $label }}</p>
                <p class="num mt-1 text-xl font-black">{{ number_format($s['done']) }}<span class="font-sans text-sm text-mist"> / {{ number_format($s['total']) }}</span></p>
                <p @class(['text-xs', 'text-signal font-bold' => $s['pending'] > 0, 'text-mist' => $s['pending'] === 0])>대기 {{ number_format($s['pending']) }}개</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.translations.test') }}">@csrf<input type="hidden" name="lang" value="{{ $code }}">
                        <button class="btn-ghost !px-3 !py-1.5 text-xs" @disabled(! $ready)>연결 테스트</button></form>
                    <form method="POST" action="{{ route('admin.translations.run') }}">@csrf<input type="hidden" name="lang" value="{{ $code }}">
                        <button class="btn-ink !px-3 !py-1.5 text-xs" @disabled(! $ready || $s['pending'] === 0)>번역 (최대 150개)</button></form>
                </div>
            </div>
        @endforeach
    </div>

    <h2 class="mb-3 text-lg font-bold">오역 교정 사전</h2>
    <form method="POST" action="{{ route('admin.translations.fixes.store') }}" class="mb-3 flex flex-wrap items-end gap-2 rounded-3xl bg-white p-4 ring-1 ring-ink/5">
        @csrf
        <label class="text-xs text-mist">언어
            <select name="lang" class="field mt-1 block !py-2">
                @foreach ($langs as $code => $label)<option value="{{ $code }}" @selected(old('lang') === $code)>{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="min-w-48 flex-1 text-xs text-mist">구글이 잘못 번역한 말
            <input name="wrong" value="{{ old('wrong') }}" required maxlength="200" class="field mt-1 block w-full !py-2" placeholder="Organic Gasoline"></label>
        <label class="min-w-48 flex-1 text-xs text-mist">올바른 번역
            <input name="right" value="{{ old('right') }}" required maxlength="200" class="field mt-1 block w-full !py-2" placeholder="Organic Gassosa"></label>
        <button class="btn-ink !py-2.5 text-sm">넣기</button>
        @foreach (['lang', 'wrong', 'right'] as $f)
            @error($f)<p class="w-full text-xs font-semibold text-signal">{{ $message }}</p>@enderror
        @endforeach
    </form>
    <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
        <table class="w-full min-w-[560px] text-sm">
            <thead class="border-b border-ink/5 text-left text-xs text-mist"><tr><th class="px-5 py-3 font-semibold">언어</th><th class="px-3 py-3 font-semibold">잘못된 번역</th><th class="px-3 py-3 font-semibold">올바른 번역</th><th class="px-5 py-3"></th></tr></thead>
            <tbody class="divide-y divide-ink/5">
                @forelse ($fixes as $f)
                    <tr>
                        <td class="px-5 py-3">{{ $langs[$f->lang] ?? $f->lang }}</td>
                        <td class="px-3 py-3 text-mist line-through">{{ $f->wrong }}</td>
                        <td class="px-3 py-3 font-bold">{{ $f->right }}</td>
                        <td class="px-5 py-3 text-right">
                            <form method="POST" action="{{ route('admin.translations.fixes.destroy', $f) }}" onsubmit="return confirm('이 교정을 뺄까요?')">@csrf @method('DELETE')
                                <button class="text-xs font-semibold text-signal">빼기</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-5 py-8 text-center text-mist">아직 교정이 없어요.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
