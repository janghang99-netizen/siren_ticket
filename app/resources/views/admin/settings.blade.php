@extends('layouts.backoffice')
@section('title', '수수료 · 정산주기 설정')
@section('eyebrow', '설정')

@php($cycles = \App\Models\Vendor::CYCLES)
@php($days = [1 => '월', 2 => '화', 3 => '수', 4 => '목', 5 => '금', 6 => '토', 7 => '일'])

@section('content')
<form method="POST" action="{{ route('admin.settings.save') }}" class="space-y-6"
      x-data="{ cycles: @js($vendors->mapWithKeys(fn ($v) => [$v->id => $v->settlement_cycle])), dflt: @js($settings['default_settlement_cycle']) }">
    @csrf @method('PUT')

    <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
        <h2 class="font-bold">기본값 <span class="text-xs font-normal text-mist">새로 입점하는 업체에 적용돼요</span></h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <div>
                <label class="label" for="d-rate">판매 수수료 (%)</label>
                <input id="d-rate" name="defaults[default_commission_rate]" type="number" step="0.01" min="0" max="50" class="field num" value="{{ old('defaults.default_commission_rate', $settings['default_commission_rate']) }}" required>
            </div>
            <div>
                <label class="label" for="d-vat">수수료 부가세 (%)</label>
                <input id="d-vat" name="defaults[commission_vat_rate]" type="number" step="0.1" min="0" max="20" class="field num" value="{{ old('defaults.commission_vat_rate', $settings['commission_vat_rate']) }}" required>
            </div>
            <div>
                <label class="label" for="d-cycle">정산주기</label>
                <select id="d-cycle" name="defaults[default_settlement_cycle]" class="field" x-model="dflt">
                    @foreach ($cycles as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="label" for="d-day">정산일</label>
                <select id="d-day" name="defaults[default_settlement_day]" class="field" x-show="dflt !== 'monthly'" :disabled="dflt === 'monthly'">
                    @foreach ($days as $k => $l)<option value="{{ $k }}" @selected((int) $settings['default_settlement_day'] === $k)>{{ $l }}요일</option>@endforeach
                </select>
                <select name="defaults[default_settlement_day]" class="field" x-show="dflt === 'monthly'" x-cloak :disabled="dflt !== 'monthly'" aria-label="정산일 (매월)">
                    @for ($d = 1; $d <= 28; $d++)<option value="{{ $d }}" @selected((int) $settings['default_settlement_day'] === $d)>매월 {{ $d }}일</option>@endfor
                </select>
            </div>
            <div>
                <label class="label" for="d-delay">지급일 (정산 후)</label>
                <input id="d-delay" name="defaults[default_payout_delay_days]" type="number" min="0" max="30" class="field num" value="{{ old('defaults.default_payout_delay_days', $settings['default_payout_delay_days']) }}" required>
            </div>
        </div>
        <p class="mt-3 text-xs text-mist">입점계약 제7조 기준: 판매금액의 8.5%(부가세 별도), 정산주기 2주. 정산 대상은 수령완료(구매확정) 주문이고, 취소·환불 주문에는 수수료가 없어요.</p>
    </section>

    <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
        <h2 class="font-bold">업체별 · 매장별</h2>
        <p class="mt-1 text-sm text-mist">수수료율은 주문할 때의 값이 주문에 기록돼요. 바꾸면 이후 주문부터 적용되고, 지난 주문 정산은 바뀌지 않아요.</p>

        <div class="mt-4 space-y-3">
            @forelse ($vendors as $v)
                <div class="rounded-2xl bg-paper p-4">
                    <div class="grid items-end gap-3 lg:grid-cols-[1.4fr_repeat(4,1fr)]">
                        <div>
                            <a href="{{ route('admin.vendors.show', $v) }}" class="row-link">{{ $v->name }}</a>
                            <span @class(['chip ml-1', 'bg-stick/10 text-stick' => $v->isActive(), 'bg-ink/10' => ! $v->isActive()])>{{ $v->stateLabel() }}</span>
                        </div>
                        <div>
                            <label class="text-xs text-mist" for="v{{ $v->id }}-rate">업체 수수료 %</label>
                            <input id="v{{ $v->id }}-rate" name="vendors[{{ $v->id }}][commission_rate]" type="number" step="0.01" min="0" max="50" class="field num !py-2" value="{{ $v->commission_rate }}">
                        </div>
                        <div>
                            <label class="text-xs text-mist" for="v{{ $v->id }}-cycle">정산주기</label>
                            <select id="v{{ $v->id }}-cycle" name="vendors[{{ $v->id }}][settlement_cycle]" class="field !py-2" x-model="cycles[{{ $v->id }}]">
                                @foreach ($cycles as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-xs text-mist" for="v{{ $v->id }}-day">정산일</label>
                            <select id="v{{ $v->id }}-day" name="vendors[{{ $v->id }}][settlement_day]" class="field !py-2" x-show="cycles[{{ $v->id }}] !== 'monthly'" :disabled="cycles[{{ $v->id }}] === 'monthly'">
                                @foreach ($days as $k => $l)<option value="{{ $k }}" @selected($v->settlement_day === $k)>{{ $l }}요일</option>@endforeach
                            </select>
                            <select name="vendors[{{ $v->id }}][settlement_day]" class="field !py-2" x-show="cycles[{{ $v->id }}] === 'monthly'" x-cloak :disabled="cycles[{{ $v->id }}] !== 'monthly'" aria-label="{{ $v->name }} 정산일 (매월)">
                                @for ($d = 1; $d <= 28; $d++)<option value="{{ $d }}" @selected($v->settlement_day === $d)>매월 {{ $d }}일</option>@endfor
                            </select>
                        </div>
                        <div>
                            <label class="text-xs text-mist" for="v{{ $v->id }}-delay">지급 (+일)</label>
                            <input id="v{{ $v->id }}-delay" name="vendors[{{ $v->id }}][payout_delay_days]" type="number" min="0" max="30" class="field num !py-2" value="{{ $v->payout_delay_days }}">
                        </div>
                    </div>
                    @if ($v->stores->isNotEmpty())
                        <div class="mt-3 flex flex-wrap gap-2 border-t border-ink/5 pt-3">
                            @foreach ($v->stores as $s)
                                <label class="flex items-center gap-2 rounded-xl bg-white px-3 py-1.5 text-sm">
                                    <span>{{ $s->name }}</span>
                                    <input name="stores[{{ $s->id }}][commission_rate]" type="number" step="0.01" min="0" max="50" value="{{ $s->commission_rate }}" class="num w-16 rounded-lg bg-paper px-2 py-1 text-right text-sm" aria-label="{{ $s->name }} 수수료 %">
                                    <span class="text-xs text-mist">%</span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-sm text-mist">입점업체가 없어요.</p>
            @endforelse
        </div>
    </section>

    @if ($errors->any())<p class="rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal">{{ $errors->first() }}</p>@endif
    <button class="btn-ink">설정 저장</button>
</form>
@endsection
