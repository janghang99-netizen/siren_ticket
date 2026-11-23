@extends('layouts.backoffice')
@section('title', '수수료 · 정산주기 설정')
@section('eyebrow', '설정')

@php($cycles = \App\Models\Vendor::CYCLES)
@php($days = [1 => '월', 2 => '화', 3 => '수', 4 => '목', 5 => '금', 6 => '토', 7 => '일'])

@section('content')
{{-- 공연 티켓 판매 기능 켜기/끄기 (다담다 이관: 기본 끔 — 사이렌오더만) --}}
<section class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-3xl bg-white p-6 ring-1 {{ $ticketing ? 'ring-stick/40' : 'ring-ink/5' }}">
    <div class="min-w-0">
        <h2 class="font-bold">공연 티켓 판매 <span @class(['chip ml-1', 'bg-stick text-white' => $ticketing, 'bg-paper text-mist' => ! $ticketing])>{{ $ticketing ? '켜짐' : '꺼짐' }}</span></h2>
        <p class="mt-1 max-w-2xl text-sm text-mist">켜면 팬 화면에 좌석 예매·내 티켓, 운영사 화면에 티켓 판매·입장 검표·공연장 좌석표가 보여요.
            끄면 이것들과 팬 홈의 공연 목록이 숨고 <b>매장 주문만</b> 보여요. 끄고 켜도 데이터는 지워지지 않아요.</p>
    </div>
    <form method="POST" action="{{ route('admin.settings.ticketing') }}"
          onsubmit="return confirm('{{ $ticketing ? '공연 티켓 판매 기능을 끌까요? 팬 화면에서 예매가 숨겨져요.' : '공연 티켓 판매 기능을 켤까요? 팬 화면에 좌석 예매가 보여요.' }}')">
        @csrf
        <input type="hidden" name="enabled" value="{{ $ticketing ? 0 : 1 }}">
        <button class="{{ $ticketing ? 'btn-ghost' : 'btn-ink' }} !py-2.5 text-sm">{{ $ticketing ? '끄기' : '켜기' }}</button>
    </form>
</section>

{{-- 화면 이름 · 운영사 사업자 정보 (팬 화면 하단 — 통신판매중개자 고지) --}}
<form method="POST" action="{{ route('admin.settings.site') }}" class="mb-6 rounded-3xl bg-white p-6 ring-1 ring-ink/5">
    @csrf @method('PUT')
    <h2 class="font-bold">화면 이름 · 사업자 정보 <span class="text-xs font-normal text-mist">팬 화면 맨 아래에 보여요</span></h2>
    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div>
                <label class="label" for="site-brand_name">화면 이름</label>
                <input id="site-brand_name" name="brand_name" class="field" value="{{ old('brand_name', $site['brand_name']) }}" placeholder="다담다" required>
                @error('brand_name')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="site-company_name">상호</label>
                <input id="site-company_name" name="company_name" class="field" value="{{ old('company_name', $site['company_name']) }}" placeholder="주식회사 태라글로벌" required>
                @error('company_name')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="site-company_ceo">대표</label>
                <input id="site-company_ceo" name="company_ceo" class="field" value="{{ old('company_ceo', $site['company_ceo']) }}" placeholder="">
                @error('company_ceo')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="site-company_reg_no">사업자등록번호</label>
                <input id="site-company_reg_no" name="company_reg_no" class="field" value="{{ old('company_reg_no', $site['company_reg_no']) }}" placeholder="000-00-00000">
                @error('company_reg_no')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="site-company_mail_order_no">통신판매업신고</label>
                <input id="site-company_mail_order_no" name="company_mail_order_no" class="field" value="{{ old('company_mail_order_no', $site['company_mail_order_no']) }}" placeholder="">
                @error('company_mail_order_no')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="site-company_address">주소</label>
                <input id="site-company_address" name="company_address" class="field" value="{{ old('company_address', $site['company_address']) }}" placeholder="">
                @error('company_address')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="site-company_phone">고객센터</label>
                <input id="site-company_phone" name="company_phone" class="field" value="{{ old('company_phone', $site['company_phone']) }}" placeholder="">
                @error('company_phone')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="site-company_email">이메일</label>
                <input id="site-company_email" name="company_email" class="field" value="{{ old('company_email', $site['company_email']) }}" placeholder="" type="email">
                @error('company_email')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
    </div>
    <label class="mt-4 flex items-start gap-2 text-sm">
        <input type="checkbox" name="company_info_visible" value="1" class="mt-0.5" @checked(old('company_info_visible', $site['company_info_visible']) === '1')>
        <span><b>화면 하단에 사업자 정보 보이기</b> <span class="text-mist">— 개발·시연 서버에서는 끄세요. 실제 서비스에서는 전자상거래법 고지라 켜 두어야 해요.</span></span>
    </label>
    <button class="btn-ink mt-4 !py-2.5 text-sm">사업자 정보 저장</button>
</form>

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
            <div>
                <label class="label" for="d-due">상품판매 정산예정일 (주문일 + 일)</label>
                <input id="d-due" name="defaults[settle_due_days]" type="number" min="0" max="60" class="field num" value="{{ old('defaults.settle_due_days', $settings['settle_due_days']) }}" required>
            </div>
        </div>
        <p class="mt-3 text-xs text-mist">입점계약 제7조 기준: 판매금액의 8.5%(부가세 별도), 정산주기 2주. 정산 대상은 수령완료(구매확정) 주문이고, 취소·환불 주문에는 수수료가 없어요.</p>
    </section>

    <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
        <h2 class="font-bold">마일리지 <span class="text-xs font-normal text-mist">매장 주문·공연 티켓 공통</span></h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                'mileage_min_use' => ['최소 사용액 (원)', 0, 100000, 100],
                'mileage_use_max_rate' => ['결제액 중 최대 사용 (%)', 0, 100, 1],
                'mileage_product_max_rate' => ['업체 상품 적립 상한 (판매가 %)', 0, 50, 1],
                'ticket_mileage_rate' => ['공연 관람 적립 (티켓 결제액 %)', 0, 20, 0.1],
            ] as $key => [$label, $min, $max, $step])
                <div>
                    <label class="label" for="m-{{ $key }}">{{ $label }}</label>
                    <input id="m-{{ $key }}" name="defaults[{{ $key }}]" type="number" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}" class="field num" value="{{ old('defaults.'.$key, $settings[$key]) }}" required>
                </div>
            @endforeach
        </div>
        <p class="mt-3 text-xs text-mist">매장 주문 적립은 업체가 상품마다 정하고 업체가 부담해요(정산에서 빠짐). 공연 관람 적립과 마일리지 사용분은 운영사가 부담해요.</p>
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
