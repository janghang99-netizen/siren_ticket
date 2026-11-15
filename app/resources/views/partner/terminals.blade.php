@extends('layouts.backoffice')
@section('title', '카드 단말기')
@section('eyebrow', $vendor->name.' · 매장 '.$stores->count().'곳')

@section('content')
{{-- 매장별 현장 카드 단말기: 등록 · 이름/밴사 수정 · 사용 중지 · 삭제 --}}
<section class="mb-6 rounded-3xl bg-white p-5 text-sm leading-relaxed ring-1 ring-ink/5">
    <h2 class="font-bold">단말기는 왜 등록하나요</h2>
    <p class="mt-1 text-ink/80">현장판매에서 카드로 받을 때 <b>어느 단말기</b>로 결제했는지와 <b>승인번호</b>를 남겨요. 정산 때 단말기(밴사) 매출과 SIREN 매출을 맞춰 볼 수 있어요.
        매장마다 여러 대를 등록할 수 있고, 고장·교체한 단말기는 <b>사용 중지</b>하면 현장판매에서 고를 수 없어요. 결제 기록이 있는 단말기는 기록 보존을 위해 지우지 않고 사용 중지만 돼요.</p>
</section>

<datalist id="providers">@foreach ($providers as $pv)<option value="{{ $pv }}">@endforeach</datalist>

<div class="space-y-5">
    @foreach ($stores as $s)
        @php($list = $terminals->get($s->id, collect()))
        <section class="rounded-3xl bg-white p-5 ring-1 ring-ink/5" id="store-{{ $s->id }}">
            <h2 class="flex flex-wrap items-center justify-between gap-2 font-bold">
                <span>{{ $s->name }} <span class="font-normal text-mist">· 단말기 {{ $list->where('is_active', true)->count() }}대 사용 중</span></span>
                @if ($list->where('is_active', true)->isEmpty())<span class="chip bg-signal/10 text-xs text-signal">카드 결제 불가 · 단말기를 등록해 주세요</span>@endif
            </h2>

            <ul class="mt-3 divide-y divide-ink/5">
                @forelse ($list as $t)
                    <li @class(['flex flex-wrap items-center gap-2 py-2.5 text-sm', 'opacity-50' => ! $t->is_active])>
                        <span class="num w-36 shrink-0 font-bold">{{ $t->tid }}</span>
                        <form method="POST" action="{{ route('partner.terminals.update', $t) }}" class="flex flex-1 flex-wrap items-center gap-2">
                            @csrf @method('PUT')
                            <input name="provider" value="{{ $t->provider }}" list="providers" maxlength="30" required class="field !w-32 !py-2" aria-label="{{ $t->tid }} 밴사">
                            <input name="label" value="{{ $t->label }}" maxlength="60" class="field !w-44 !py-2" placeholder="이름 (1번 카운터)" aria-label="{{ $t->tid }} 이름">
                            <button class="btn-ghost !px-3 !py-2 text-xs">저장</button>
                        </form>
                        <span @class(['chip text-xs', 'bg-stick/10 text-stick' => $t->is_active, 'bg-ink/10' => ! $t->is_active])>{{ $t->is_active ? '사용 중' : '사용 중지' }}</span>
                        <form method="POST" action="{{ route('partner.terminals.toggle', $t) }}">@csrf @method('PATCH')
                            <button class="btn-ghost !px-3 !py-2 text-xs">{{ $t->is_active ? '사용 중지' : '다시 사용' }}</button></form>
                        <form method="POST" action="{{ route('partner.terminals.destroy', $t) }}" onsubmit="return confirm(@js('단말기 '.$t->tid.' 을(를) 지울까요? 결제 기록이 있으면 사용 중지만 돼요.'))">@csrf @method('DELETE')
                            <button class="px-2 text-xs font-semibold text-signal">삭제</button></form>
                    </li>
                @empty
                    <li class="py-2 text-sm text-mist">등록된 단말기가 없어요.</li>
                @endforelse
            </ul>

            <form method="POST" action="{{ route('partner.terminals.store') }}" class="mt-3 flex flex-wrap gap-2 border-t border-ink/5 pt-3">
                @csrf
                <input type="hidden" name="store_id" value="{{ $s->id }}">
                <input name="provider" list="providers" maxlength="30" required class="field !w-32 !py-2" placeholder="밴사 (KSNET)" aria-label="{{ $s->name }} 새 단말기 밴사"
                       value="{{ (int) old('store_id') === $s->id ? old('provider') : '' }}">
                <input name="tid" maxlength="40" required class="field num !w-44 !py-2" placeholder="단말기 ID (TID)" aria-label="{{ $s->name }} 새 단말기 ID"
                       value="{{ (int) old('store_id') === $s->id ? old('tid') : '' }}">
                <input name="label" maxlength="60" class="field !w-44 !py-2" placeholder="이름 (1번 카운터)" aria-label="{{ $s->name }} 새 단말기 이름"
                       value="{{ (int) old('store_id') === $s->id ? old('label') : '' }}">
                <button class="btn-ink !py-2 text-sm">단말기 등록</button>
                @if ((int) old('store_id') === $s->id)
                    @foreach (['provider', 'tid', 'label', 'store_id'] as $f)
                        @error($f)<p class="w-full text-sm font-semibold text-signal">{{ $message }}</p>@enderror
                    @endforeach
                @endif
            </form>
        </section>
    @endforeach
</div>
@endsection
