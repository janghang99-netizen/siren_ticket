@extends('layouts.backoffice')
@section('title', $vendor->name)
@section('eyebrow', '입점업체 · '.$vendor->stateLabel())

@section('actions')
    <a href="{{ route('admin.vendors.edit', $vendor) }}" class="btn-ghost !py-2.5 text-sm">정보 수정</a>
    @if ($vendor->isActive())<a href="{{ route('admin.stores.create', ['vendor' => $vendor->id]) }}" class="btn-ink !py-2.5 text-sm">매장 추가</a>@endif
@endsection

@section('content')
<div class="grid gap-5 xl:grid-cols-[1fr_360px]">
    <div class="space-y-5">
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/5"><p class="text-xs text-mist">최근 30일 판매</p><p class="num mt-1 text-2xl font-black">{{ number_format($salesAmount) }}<span class="font-sans text-sm">원</span></p></div>
            <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/5"><p class="text-xs text-mist">정산 대기</p><p class="num mt-1 text-2xl font-black">{{ number_format($unsettledAmount) }}<span class="font-sans text-sm">원</span></p></div>
            <div class="rounded-3xl bg-ink p-5 text-paper"><p class="text-xs text-paper/60">다음 자동 정산</p><p class="num mt-1 text-2xl font-black">{{ $nextSettlement->format('m.d') }}</p><p class="text-xs text-paper/60">{{ $vendor->cycleLabel() }} · 지급 +{{ $vendor->payout_delay_days }}일</p></div>
        </div>

        <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <h2 class="font-bold">판매자 정보 <span class="text-xs font-normal text-mist">팬 매장 화면에 고지돼요 (전자상거래법 §20)</span></h2>
            <dl class="mt-3 grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                @forelse ($vendor->disclosure() as $k => $v)
                    <div class="flex justify-between gap-3"><dt class="text-mist">{{ $k }}</dt><dd class="text-right">{{ $v }}</dd></div>
                @empty
                    <p class="text-mist">등록된 정보가 없어요.</p>
                @endforelse
                <div class="flex justify-between gap-3"><dt class="text-mist">정산 계좌</dt><dd class="text-right">{{ $vendor->bank_name }} {{ $vendor->bank_account }} {{ $vendor->account_holder }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-mist">수수료</dt><dd class="num">{{ rtrim(rtrim($vendor->commission_rate, '0'), '.') }}% <span class="text-mist">+ 부가세</span></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-mist">로그인 계정</dt><dd>{{ $vendor->users->pluck('email')->join(', ') }}</dd></div>
            </dl>
        </section>

        <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <h2 class="font-bold">운영 매장 <span class="num text-mist">{{ $vendor->stores->count() }}</span></h2>
            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                @forelse ($vendor->stores as $s)
                    <a href="{{ route('admin.stores.show', $s->id) }}" class="flex items-center justify-between rounded-2xl bg-paper px-4 py-3 text-sm transition hover:ring-1 hover:ring-stick/40">
                        <span><b>{{ $s->name }}</b> <span class="num text-xs text-mist">{{ $s->code }} · 상품 {{ $s->products_count }}</span></span>
                        <span @class(['chip', 'bg-stick/10 text-stick' => $s->is_open, 'bg-ink/10' => ! $s->is_open])>{{ $s->is_open ? '영업 중' : '영업 종료' }}</span>
                    </a>
                @empty
                    <p class="text-sm text-mist">아직 매장이 없어요.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <h2 class="mb-3 font-bold">최근 정산</h2>
            @include('backoffice.settlement-list', ['settlements' => new \Illuminate\Pagination\Paginator($settlements, 10), 'showVendor' => false, 'route' => 'admin.settlements.show'])
            @if ($pendingAdjustments->isNotEmpty())
                <h3 class="mt-5 text-sm font-bold">다음 정산에서 공제될 항목</h3>
                <ul class="mt-2 space-y-1 text-sm">
                    @foreach ($pendingAdjustments as $a)
                        <li class="flex justify-between gap-3"><span>{{ $a->reason }}</span><span class="num font-bold text-signal">−{{ number_format($a->amount) }}</span></li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <aside class="space-y-5">
        {{-- A1 서류 심사 --}}
        <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <h2 class="font-bold">입점 서류</h2>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach (\App\Models\Vendor::DOCUMENTS as $type => $label)
                    <li class="flex items-center justify-between gap-3">
                        <span>{{ $label }}</span>
                        @if (! empty($vendor->documents[$type]))
                            <a href="{{ route('admin.vendors.document', [$vendor, $type]) }}" class="btn-ghost !px-3 !py-1.5 text-xs">내려받기</a>
                        @else
                            <span class="text-xs text-mist">{{ $vendor->documents ? '없음' : '운영사 직접 등록' }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5" x-data="{ act: null }">
            <h2 class="font-bold">심사 · 상태</h2>
            <p class="mt-1 text-sm">지금: <b>{{ $vendor->stateLabel() }}</b>
                @if ($vendor->approved_at)<span class="num text-xs text-mist">(승인 {{ $vendor->approved_at->format('Y.m.d') }})</span>@endif</p>
            @if ($vendor->state_reason)<p class="mt-2 rounded-2xl bg-signal/10 px-3 py-2 text-sm text-signal">사유: {{ $vendor->state_reason }}</p>@endif

            <div class="mt-4 flex flex-wrap gap-2">
                @if ($vendor->state !== 'approved')
                    <form method="POST" action="{{ route('admin.vendors.state', $vendor) }}">@csrf @method('PATCH')
                        <input type="hidden" name="state" value="approved">
                        <button class="btn-ink !py-2 text-sm">{{ $vendor->state === 'suspended' ? '정지 해제' : '승인' }}</button>
                    </form>
                @endif
                @if ($vendor->state === 'pending')<button type="button" @click="act = 'rejected'" class="btn-ghost !py-2 text-sm text-signal">반려</button>@endif
                @if ($vendor->state === 'approved')<button type="button" @click="act = 'suspended'" class="btn-ghost !py-2 text-sm text-signal">이용 정지</button>@endif
            </div>

            <form x-show="act" x-cloak method="POST" action="{{ route('admin.vendors.state', $vendor) }}" class="mt-3">
                @csrf @method('PATCH')
                <input type="hidden" name="state" :value="act">
                <label for="reason" class="label" x-text="act === 'rejected' ? '반려 사유' : '정지 사유'"></label>
                <input id="reason" name="reason" class="field !py-2.5" placeholder="업체에 그대로 보여요" required>
                <p x-show="act === 'suspended'" class="mt-1 text-xs text-mist">정지하면 이 업체의 매장은 모두 영업 종료로 바뀌어요.</p>
                <button class="btn mt-2 w-full bg-signal !py-2.5 text-sm text-white" x-text="act === 'rejected' ? '반려하기' : '정지하기'"></button>
            </form>
            @error('reason')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        </section>
    </aside>
</div>
@endsection
