@extends('layouts.backoffice')
@section('title', $event ? '공연 수정' : '공연 등록')
@section('eyebrow', '좌석티켓')

@php($e = $event)
@php($dt = fn ($field, $fallback = null) => old($field, $e?->{$field}?->format('Y-m-d\TH:i') ?? $fallback))

@section('content')
<form method="POST" action="{{ $e ? route('admin.events.update', $e->id) : route('admin.events.store') }}" class="grid max-w-5xl gap-6 xl:grid-cols-[1fr_360px]"
      x-data="{ venue: @js((string) old('venue_id', $e?->venue_id ?? '')), map: @js((string) old('seat_map_id', '')) }">
    @csrf
    @if ($e) @method('PUT') @endif

    <div class="space-y-6">
        <section class="space-y-4 rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <h2 class="font-bold">공연 정보</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="artist" class="label">아티스트</label>
                    <input id="artist" name="artist" class="field" value="{{ old('artist', $e?->artist) }}" required>
                    @error('artist')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="venue_id" class="label">공연장 <a href="{{ route('admin.venues.index') }}" class="font-normal text-stick">공연장 목록</a></label>
                    <select id="venue_id" name="venue_id" class="field" x-model="venue" @change="map = ''" required>
                        <option value="">공연장을 고르세요</option>
                        @foreach ($venues as $v)
                            <option value="{{ $v->id }}">{{ $v->name }}</option>
                        @endforeach
                    </select>
                    @error('venue_id')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
                    @error('venue')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
                </div>
            </div>
            <div>
                <label for="name" class="label">공연명</label>
                <input id="name" name="name" class="field" value="{{ old('name', $e?->name) }}" placeholder="예: WORLD TOUR 〈ORBIT〉 IN SEOUL" required>
                @error('name')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="starts_at" class="label">공연 시작</label>
                    <input id="starts_at" name="starts_at" type="datetime-local" class="field num !text-sm" value="{{ $dt('starts_at') }}" required>
                    @error('starts_at')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="doors_at" class="label">입장 시작</label>
                    <input id="doors_at" name="doors_at" type="datetime-local" class="field num !text-sm" value="{{ $dt('doors_at') }}">
                    @error('doors_at')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="sales_open_at" class="label">예매 오픈</label>
                    <input id="sales_open_at" name="sales_open_at" type="datetime-local" class="field num !text-sm" value="{{ $dt('sales_open_at') }}">
                    @error('sales_open_at')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="theme_color" class="label">팬덤 컬러 <span class="font-normal text-mist">(좌석도·티켓 색)</span></label>
                    <input id="theme_color" name="theme_color" type="color" class="h-12 w-full cursor-pointer rounded-2xl bg-white ring-1 ring-ink/10" value="{{ old('theme_color', $e?->theme_color ?? '#6C4BFF') }}">
                </div>
                <div>
                    <label for="max_per_buyer" class="label">1인 최대 매수</label>
                    <input id="max_per_buyer" name="max_per_buyer" type="number" min="1" max="10" class="field num" value="{{ old('max_per_buyer', $e?->max_per_buyer ?? 4) }}" required>
                </div>
            </div>
        </section>

        @if ($ticketing)
        <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            @if (! $e)
                {{-- 좌석 배치: 공연장 좌석표(배치도 이미지) 또는 기본 아레나 보울 --}}
                <div>
                    <h2 class="font-bold">좌석 배치</h2>
                    <p class="mt-1 text-sm text-mist">공연장 좌석표를 고르면 그 배치도의 구역·등급·좌석으로 예매가 열리고, 팬이 좌석을 직접 골라요.
                        <a href="{{ route('admin.seat-maps.index') }}" class="row-link text-ink">좌석표 관리</a></p>
                    <div class="mt-4 grid gap-2 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-3 rounded-2xl p-3 ring-1 transition" :class="map === '' ? 'bg-ink text-white ring-ink' : 'bg-paper ring-transparent'">
                            <input type="radio" name="seat_map_id" value="" x-model="map" class="mt-1">
                            <span><b>기본 아레나 보울</b><span class="block text-xs opacity-70">플로어·1층·2층 17구역 · 좌석 자동 배정</span></span>
                        </label>
                        @foreach ($seatMaps as $m)
                            <label x-show="venue === '{{ $m->venue_id }}'" class="flex cursor-pointer items-start gap-3 rounded-2xl p-3 ring-1 transition" :class="map === '{{ $m->id }}' ? 'bg-ink text-white ring-ink' : 'bg-paper ring-transparent'">
                                <input type="radio" name="seat_map_id" value="{{ $m->id }}" x-model="map" class="mt-1" @disabled($m->sections->isEmpty())>
                                <span class="min-w-0"><b class="block truncate">{{ $m->name }}</b>
                                    <span class="block text-xs opacity-70">{{ $m->venue }} · 구역 {{ $m->sections->count() }}개 · {{ number_format($m->totalSeats()) }}석{{ $m->sections->isEmpty() ? ' (구역 없음)' : '' }}</span></span>
                            </label>
                        @endforeach
                    </div>
                    <p x-show="venue && !@js($seatMaps->pluck('venue_id')->map(fn ($id) => (string) $id)->values()).includes(venue)" class="mt-2 text-xs text-mist">
                        이 공연장에는 아직 좌석표가 없어요. <a :href="@js(route('admin.seat-maps.create')) + '?venue=' + venue" class="row-link text-ink">좌석 배치도 올리기</a></p>
                    @error('seat_map_id')<p class="mt-2 text-sm text-signal">{{ $message }}</p>@enderror
                    <p x-show="map !== ''" x-cloak class="mt-3 rounded-2xl bg-paper px-4 py-3 text-xs text-mist">등급별 가격·좌석 수는 좌석표에서 가져와요. 등록 후 구역별 가격은 여기서 바꿀 수 있어요.</p>
                <div x-show="map === ''" class="mt-6">
                <h2 class="font-bold">좌석 등급 · 가격</h2>
                <p class="mt-1 text-sm text-mist">플로어(VIP) 3구역, 1층(R) 6구역, 2층(S) 8구역이 만들어져요. 등록 후 구역별로 바꿀 수 있어요.</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    @foreach (['VIP' => [176000, 1500, '플로어 3구역'], 'R' => [154000, 2000, '1층 6구역'], 'S' => [132000, 1200, '2층 8구역']] as $tier => [$price, $cap, $desc])
                        <div class="rounded-2xl bg-paper p-4">
                            <p class="font-bold">{{ $tier }}석 <span class="text-xs font-normal text-mist">{{ $desc }}</span></p>
                            <label class="mt-3 block text-xs text-mist" for="tier-{{ $tier }}-price">가격(원)</label>
                            <input id="tier-{{ $tier }}-price" name="tiers[{{ $tier }}][price]" :disabled="map !== ''" type="number" min="0" step="1000" class="field num !py-2.5" value="{{ old("tiers.$tier.price", $price) }}" required>
                            <label class="mt-2 block text-xs text-mist" for="tier-{{ $tier }}-cap">구역당 좌석 수</label>
                            <input id="tier-{{ $tier }}-cap" name="tiers[{{ $tier }}][capacity]" :disabled="map !== ''" type="number" min="1" max="5000" class="field num !py-2.5" value="{{ old("tiers.$tier.capacity", $cap) }}" required>
                        </div>
                    @endforeach
                </div>
                </div>
                </div>
            @else
                <h2 class="font-bold">구역별 가격 · 좌석 수</h2>
                <p class="mt-1 text-sm text-mist">이미 팔린 좌석보다 적게 줄일 수는 없어요.</p>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full min-w-[480px] text-sm">
                        <thead class="text-left text-xs text-mist">
                            <tr><th class="py-2 font-semibold">구역</th><th class="py-2 font-semibold">가격(원)</th><th class="py-2 font-semibold">좌석 수</th><th class="py-2 text-right font-semibold">판매</th></tr>
                        </thead>
                        <tbody class="divide-y divide-ink/5">
                            @foreach ($e->sections as $s)
                                <tr>
                                    <td class="py-2"><span class="num font-bold">{{ $s->code }}</span> <span class="text-xs text-mist">{{ $s->tier }}</span></td>
                                    <td class="py-2 pr-2"><input name="sections[{{ $s->id }}][price]" type="number" min="0" step="1000" class="field num !py-2" value="{{ old("sections.{$s->id}.price", $s->price) }}" aria-label="{{ $s->code }}구역 가격"></td>
                                    <td class="py-2 pr-2">
                                        @if ($s->hasSeatGrid())
                                            {{-- 좌석표 구역: 좌석 수 = 열 × 열당 좌석 (좌석표에서 정함) --}}
                                            <input type="hidden" name="sections[{{ $s->id }}][capacity]" value="{{ $s->capacity }}">
                                            <span class="num text-sm">{{ number_format($s->capacity) }}</span> <span class="text-xs text-mist">{{ $s->rows }}열×{{ $s->seats_per_row }}</span>
                                        @else
                                            <input name="sections[{{ $s->id }}][capacity]" type="number" min="{{ $s->sold }}" max="5000" class="field num !py-2" value="{{ old("sections.{$s->id}.capacity", $s->capacity) }}" aria-label="{{ $s->code }}구역 좌석 수">
                                        @endif
                                    </td>
                                    <td class="num py-2 text-right">{{ number_format($s->sold) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
        @endif
    </div>

    <div class="space-y-4">
        @if ($e && $ticketing)
            <div class="rounded-3xl bg-ink p-4">
                @include($e->seat_map_id ? 'partials.seatmap' : 'partials.bowl', ['event' => $e])
            </div>
            @if ($e->sections->contains(fn ($s) => $s->hasSeatGrid()))
                <a href="{{ route('admin.events.seats', $e->id) }}" class="btn-ghost w-full">좌석 막기 (판매 제외 · 보류)
                    @if ($e->sections->sum('blocked'))<span class="num ml-1 text-xs text-mist">{{ number_format($e->sections->sum('blocked')) }}석</span>@endif</a>
            @endif
        @endif
        @if ($e?->isCancelled())
            <p class="rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal">취소된 공연이에요 ({{ $e->cancelled_at?->format('m.d H:i') }}) · {{ $e->cancel_reason }}</p>
        @else
            <button class="btn-ink w-full">{{ $e ? '저장' : '공연 등록' }}</button>
        @endif
        <a href="{{ route('admin.events.index') }}" class="btn-ghost w-full">목록으로</a>
    </div>
</form>

{{-- F4 공연 취소: 티켓 전액 일괄 환불 + 매장 주문 환불 --}}
@if ($e && ! $e->isCancelled())
    <details class="mt-8 max-w-5xl rounded-3xl bg-white p-6 ring-1 ring-signal/30">
        <summary class="cursor-pointer font-bold text-signal">공연 취소</summary>
        <form method="POST" action="{{ route('admin.events.cancel', $e->id) }}" class="mt-4 space-y-3"
              onsubmit="return confirm('정말 공연을 취소할까요? 되돌릴 수 없어요.')">
            @csrf
            <p class="text-sm text-mist">판매된 티켓을 모두 <b>수수료 없이 전액 환불</b>하고, 이 공연 시간대에 받은 매장 주문(수령 전)도 환불해요. 매장 주문 시간대는 모두 닫혀요.</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="cancel-reason" class="label">취소 사유 <span class="font-normal text-mist">(예매자에게 보여요)</span></label>
                    <input id="cancel-reason" name="reason" class="field" required>
                    @error('reason')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="confirm-name" class="label">확인을 위해 아티스트 이름 ‘{{ $e->artist }}’ 입력</label>
                    <input id="confirm-name" name="confirm_name" class="field" required autocomplete="off">
                    @error('confirm_name')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
                </div>
            </div>
            <button class="btn bg-signal !py-2.5 text-sm text-white">공연 취소하고 전액 환불</button>
        </form>
    </details>
@endif
@endsection
