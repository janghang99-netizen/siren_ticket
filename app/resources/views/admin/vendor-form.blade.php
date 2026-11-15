@extends('layouts.backoffice')
@section('title', $vendor ? $vendor->name : '업체 등록')
@section('eyebrow', '입점업체')

@php($v = $vendor)

@section('content')
<form method="POST" action="{{ $v ? route('admin.vendors.update', $v) : route('admin.vendors.store') }}" class="grid max-w-5xl gap-6 xl:grid-cols-[1fr_340px]">
    @csrf
    @if ($v) @method('PUT') @endif

    <div class="space-y-6">
        <section class="space-y-4 rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <h2 class="font-bold">업체 정보</h2>
            <div>
                <label for="name" class="label">업체명</label>
                <input id="name" name="name" class="field" value="{{ old('name', $v?->name) }}" required>
                @error('name')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach (['business_no' => '사업자등록번호', 'mail_order_no' => '통신판매업 신고번호', 'representative' => '대표자', 'liquor_license_no' => '주류판매 신고번호 (주류 판매 시)', 'contact_phone' => '대표 연락처', 'contact_email' => '담당자 이메일', 'address' => '사업장 주소'] as $f => $l)
                    <div>
                        <label for="{{ $f }}" class="label">{{ $l }}</label>
                        <input id="{{ $f }}" name="{{ $f }}" class="field" value="{{ old($f, $v?->{$f}) }}">
                        @error($f)<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>
        </section>

        <section class="space-y-4 rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <h2 class="font-bold">정산</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach (['bank_name' => '은행', 'bank_account' => '계좌번호', 'account_holder' => '예금주'] as $f => $l)
                    <div>
                        <label for="{{ $f }}" class="label">{{ $l }}</label>
                        <input id="{{ $f }}" name="{{ $f }}" class="field" value="{{ old($f, $v?->{$f}) }}">
                    </div>
                @endforeach
            </div>
            @php($cycle = old('settlement_cycle', $v?->settlement_cycle ?? $defaults['default_settlement_cycle']))
            <div class="grid gap-4 sm:grid-cols-4" x-data="{ cycle: @js($cycle) }">
                <div>
                    <label for="commission_rate" class="label">수수료율 (%)</label>
                    <input id="commission_rate" name="commission_rate" type="number" step="0.01" min="0" max="50" class="field num" value="{{ old('commission_rate', $v?->commission_rate ?? $defaults['default_commission_rate']) }}" required>
                </div>
                <div>
                    <label for="settlement_cycle" class="label">정산주기</label>
                    <select id="settlement_cycle" name="settlement_cycle" class="field" x-model="cycle">
                        @foreach (\App\Models\Vendor::CYCLES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                    </select>
                </div>
                <div>
                    @php($day = (int) old('settlement_day', $v?->settlement_day ?? $defaults['default_settlement_day']))
                    <label for="settlement_day" class="label">정산일</label>
                    <select id="settlement_day" name="settlement_day" class="field" x-show="cycle !== 'monthly'" :disabled="cycle === 'monthly'">
                        @foreach ([1 => '월', 2 => '화', 3 => '수', 4 => '목', 5 => '금', 6 => '토', 7 => '일'] as $k => $l)<option value="{{ $k }}" @selected($day === $k)>{{ $l }}요일</option>@endforeach
                    </select>
                    <select name="settlement_day" class="field" x-show="cycle === 'monthly'" x-cloak :disabled="cycle !== 'monthly'" aria-label="정산일 (매월)">
                        @for ($d = 1; $d <= 28; $d++)<option value="{{ $d }}" @selected($day === $d)>매월 {{ $d }}일</option>@endfor
                    </select>
                </div>
                <div>
                    <label for="payout_delay_days" class="label">지급 (정산 후 +일)</label>
                    <input id="payout_delay_days" name="payout_delay_days" type="number" min="0" max="30" class="field num" value="{{ old('payout_delay_days', $v?->payout_delay_days ?? $defaults['default_payout_delay_days']) }}" required>
                </div>
            </div>
            <p class="text-xs text-mist">수수료는 부가세 별도예요. 매장마다 다른 수수료는 매장 화면이나 설정에서 바꿀 수 있어요.</p>
        </section>

        @unless ($v)
            <section class="space-y-4 rounded-3xl bg-white p-6 ring-1 ring-ink/5">
                <h2 class="font-bold">업체 로그인 계정</h2>
                <p class="text-sm text-mist">업체는 이 계정으로 들어와 자기 매장들의 주문·상품·정산만 봐요.</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="login_email" class="label">로그인 이메일</label>
                        <input id="login_email" name="login_email" type="email" class="field" value="{{ old('login_email') }}" autocomplete="off" required>
                        @error('login_email')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="login_password" class="label">초기 비밀번호</label>
                        <input id="login_password" name="login_password" type="password" class="field" autocomplete="new-password" minlength="8" required>
                        @error('login_password')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>
        @endunless
    </div>

    <div class="space-y-4">
        @if ($v)
            <div class="rounded-3xl bg-white p-6 text-sm ring-1 ring-ink/5">
                <p class="label">심사 · 상태</p>
                <p><b>{{ $v->stateLabel() }}</b> — 승인·반려·정지는 <a href="{{ route('admin.vendors.show', $v) }}" class="row-link">업체 화면</a>에서 해요.</p>
            </div>
        @else
            <p class="rounded-3xl bg-white p-6 text-sm text-mist ring-1 ring-ink/5">운영사가 직접 등록한 업체는 계약·서류 확인을 마친 것으로 보고 바로 승인돼요. 일반 신청은 <a href="{{ route('partner.apply') }}" class="row-link" target="_blank">입점 신청 페이지</a>로 받아 심사해요.</p>
        @endif
        @if ($v)
            <div class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
                <p class="label">운영 매장 <span class="num text-mist">{{ $v->stores->count() }}</span></p>
                <ul class="space-y-1 text-sm">
                    @forelse ($v->stores as $s)
                        <li class="flex justify-between"><a href="{{ route('admin.stores.edit', $s->id) }}" class="hover:underline">{{ $s->name }}</a> <span class="num text-mist">{{ $s->code }}</span></li>
                    @empty
                        <li class="text-mist">아직 매장이 없어요</li>
                    @endforelse
                </ul>
                <a href="{{ route('admin.stores.create', ['vendor' => $v->id]) }}" class="btn-ghost mt-3 w-full !py-2 text-sm">매장 추가</a>
            </div>
        @endif
        <button class="btn-ink w-full">{{ $v ? '저장' : '업체 등록' }}</button>
        <a href="{{ $v ? route('admin.vendors.show', $v) : route('admin.vendors.index') }}" class="btn-ghost w-full">{{ $v ? '업체 화면으로' : '목록으로' }}</a>
    </div>
</form>
@endsection
