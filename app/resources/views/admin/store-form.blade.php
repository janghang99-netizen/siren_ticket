@extends('layouts.backoffice')
@section('title', $store ? $store->name : '매장 열기')
@section('eyebrow', '매장')

@php($s = $store)
@php($initialRate = old('commission_rate', $s?->commission_rate ?? optional($vendors->firstWhere('id', $vendorId))->commission_rate ?? 8.5))

@section('content')
@if ($vendors->isEmpty())
    <div class="rounded-3xl bg-white px-6 py-12 text-center ring-1 ring-ink/5">
        <p class="font-bold">매장을 운영할 업체가 없어요</p>
        <a href="{{ route('admin.vendors.create') }}" class="btn-ink mt-5">업체 먼저 등록</a>
    </div>
@else
<form method="POST" action="{{ $s ? route('admin.stores.update', $s->id) : route('admin.stores.store') }}"
      x-data="{ rates: @js($vendors->pluck('commission_rate', 'id')), rate: @js((float) $initialRate), creating: @js(! $s) }"
      class="max-w-2xl space-y-5 rounded-3xl bg-white p-6 ring-1 ring-ink/5">
    @csrf
    @if ($s) @method('PUT') @endif

    <div>
        <label for="vendor_id" class="label">운영 업체</label>
        <select id="vendor_id" name="vendor_id" class="field" required @change="if (creating && rates[$event.target.value]) rate = rates[$event.target.value]">
            <option value="">업체를 고르세요</option>
            @foreach ($vendors as $v)
                <option value="{{ $v->id }}" @selected((int) old('vendor_id', $vendorId) === $v->id)>{{ $v->name }}{{ $v->isActive() ? '' : ' (이용 정지)' }}</option>
            @endforeach
        </select>
        @error('vendor_id')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="name" class="label">매장 이름</label>
            <input id="name" name="name" class="field" value="{{ old('name', $s?->name) }}" placeholder="예: 아레나 커피 1층점" required>
            @error('name')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="code" class="label">매장 코드 <span class="font-normal text-mist">(전광판 주소에 쓰여요)</span></label>
            <input id="code" name="code" class="field num" value="{{ old('code', $s?->code) }}" placeholder="arena7" required>
            @error('code')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        </div>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="commission_rate" class="label">수수료율 (%)</label>
            <input id="commission_rate" name="commission_rate" type="number" step="0.01" min="0" max="50" class="field num" x-model="rate" required>
            <p class="mt-1 text-xs text-mist">바꾸면 이후 주문부터 적용돼요.</p>
        </div>
        <label class="flex items-center gap-2 self-center pt-5 text-sm font-semibold">
            <input type="checkbox" name="is_open" value="1" @checked(old('is_open', $s?->is_open ?? true))> 주문 받기 (영업 중)
        </label>
    </div>
    @unless ($s)
        <p class="rounded-2xl bg-paper px-4 py-3 text-sm text-mist">매장을 열면 예정된 공연마다 공연 전·인터미션·공연 후 주문 시간대(540 / 135 / 180건)가 만들어져요.</p>
    @endunless
    <div class="flex gap-2">
        <button class="btn-ink">{{ $s ? '저장' : '매장 열기' }}</button>
        <a href="{{ route('admin.stores.index') }}" class="btn-ghost">목록으로</a>
    </div>
</form>
@endif
@endsection
