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
                @foreach (['business_no' => '사업자등록번호', 'representative' => '대표자', 'contact_phone' => '담당자 연락처', 'contact_email' => '담당자 이메일'] as $f => $l)
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
            <div class="max-w-xs">
                <label for="commission_rate" class="label">기본 수수료율 (%)</label>
                <input id="commission_rate" name="commission_rate" type="number" step="0.01" min="0" max="50" class="field num" value="{{ old('commission_rate', $v?->commission_rate ?? 8.5) }}" required>
                <p class="mt-1 text-xs text-mist">새 매장을 열 때 기본값이에요. 매장마다 바꿀 수 있어요.</p>
            </div>
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
        <div class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <span class="label">상태</span>
            @php($status = old('status', $v?->status ?? 'active'))
            <label class="flex items-center gap-2 py-1 text-sm"><input type="radio" name="status" value="active" @checked($status === 'active')> 운영 중</label>
            <label class="flex items-center gap-2 py-1 text-sm"><input type="radio" name="status" value="suspended" @checked($status === 'suspended')> 이용 정지 <span class="text-mist">(로그인 차단)</span></label>
        </div>
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
        <a href="{{ route('admin.vendors.index') }}" class="btn-ghost w-full">목록으로</a>
    </div>
</form>
@endsection
