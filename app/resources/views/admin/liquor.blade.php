@extends('layouts.backoffice')
@section('title', '주류판매 자격')
@section('eyebrow', '서류 확인 후 승인해야 주류 상품을 등록할 수 있어요')

@section('content')
    @if ($errors->any())
        <p role="alert" class="mb-4 rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal">{{ $errors->first() }}</p>
    @endif
    @if ($counts['pending'] ?? 0)
        <p class="mb-4 rounded-2xl bg-pick px-4 py-3 text-sm font-bold">확인을 기다리는 주류판매 자격 신청이 {{ $counts['pending'] }}건 있어요.</p>
    @endif

    <section class="mb-6 rounded-3xl bg-white p-6 text-sm leading-relaxed ring-1 ring-ink/5">
        <h2 class="font-bold">어떻게 확인하나요</h2>
        <p class="mt-1 text-ink/80">주류(와인 등)를 팔려는 업체는 사업자등록증·영업신고증으로 <b>영업형태</b>를 확인한 뒤 승인해야, 상품 등록 화면에서 ‘주류’를 켤 수 있어요. 승인 전에는 서버에서 막아요.</p>
        <ul class="mt-3 space-y-1.5">
            @foreach ($bizTypes as $key => $t)
                <li class="flex gap-2">
                    <span @class(['chip shrink-0', 'bg-stick/10 text-stick' => $t['allowed'], 'bg-signal/10 text-signal' => ! $t['allowed']])>{{ $t['allowed'] ? '가능' : '불가' }}</span>
                    <span><b>{{ $t['label'] }}</b> — {{ $t['guide'] }}</span>
                </li>
            @endforeach
        </ul>
        <p class="mt-3 text-xs text-mist">
            근거: 국세청 「주류의 통신판매에 관한 명령위임 고시」 제3조③3호(온라인으로 주문·결제하고 매장에서 직접 받는 방식)·제5조(구매자 성인 확인).
            판매하는 매장은 식품위생법상 주류를 팔 수 있는 영업(일반음식점·의제주류판매업면허 제과점 등)이어야 해요.
            구매자 성인 확인은 따로(주문 때 성인 확인 동의, 수령 때 실물 신분증) — 이 화면은 <b>판매 업체</b> 자격만 관리해요.
        </p>
    </section>

    <nav class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach (['all' => '전체', 'pending' => $states['pending'], 'approved' => $states['approved'], 'rejected' => $states['rejected'], 'none' => '신청 없음'] as $k => $label)
            <a href="{{ route('admin.liquor', $k === 'all' ? [] : ['status' => $k]) }}"
               @class(['chip !px-3 !py-1.5', 'bg-ink text-paper' => $filter === $k, 'bg-white ring-1 ring-ink/10' => $filter !== $k])>
                {{ $label }} <span class="num opacity-70">{{ $counts[$k] ?? 0 }}</span>
            </a>
        @endforeach
    </nav>

    @if ($vendors->isEmpty())
        <div class="rounded-3xl bg-white px-6 py-12 text-center ring-1 ring-ink/5">
            <p class="text-lg font-bold">해당하는 업체가 없어요</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($vendors as $v)
                <article class="rounded-3xl bg-white p-5 ring-1 ring-ink/5" id="vendor-{{ $v->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-bold"><a href="{{ route('admin.vendors.show', $v) }}" class="row-link">{{ $v->name }}</a>
                                <span @class(['chip ml-1',
                                    'bg-pick' => $v->liquor_status === 'pending',
                                    'bg-stick/10 text-stick' => $v->liquor_status === 'approved',
                                    'bg-signal/10 text-signal' => $v->liquor_status === 'rejected',
                                    'bg-ink/5 text-mist' => $v->liquor_status === null])>{{ $v->liquorStatusLabel() }}</span>
                            </p>
                            <p class="mt-1 text-xs text-mist">
                                사업자 <span class="num">{{ $v->business_no ?? '—' }}</span> ·
                                주류판매 신고번호 <span class="num">{{ $v->liquor_license_no ?: '—' }}</span> ·
                                영업형태 {{ $v->liquorBizLabel() ?? '—' }} ·
                                주류 상품 <span class="num">{{ $v->alcohol_products_count }}</span>개
                            </p>
                            <p class="mt-0.5 text-xs text-mist">
                                @if ($v->liquor_requested_at)신청 {{ $v->liquor_requested_at->format('Y-m-d H:i') }} · @endif
                                @if ($v->liquor_checked_at)확인 {{ $v->liquor_checked_at->format('Y-m-d H:i') }} ({{ $v->liquorChecker?->name ?? '이관' }})@endif
                            </p>
                            @if ($v->liquor_memo)<p class="mt-1 text-xs">메모: {{ $v->liquor_memo }}</p>@endif
                        </div>
                        <div class="flex flex-wrap gap-2 text-xs">
                            @foreach (['business_reg' => '사업자등록증', 'liquor_permit' => '영업신고증(주류)'] as $doc => $docLabel)
                                @if (! empty($v->documents[$doc]))
                                    <a href="{{ route('admin.vendors.document', [$v, $doc]) }}" class="btn-ghost !px-3 !py-1.5">{{ $docLabel }} 보기</a>
                                @else
                                    <span class="chip bg-ink/5 text-mist">{{ $docLabel }} 없음</span>
                                @endif
                            @endforeach
                        </div>
                    </div>

                    <form method="POST" action="{{ route('admin.liquor.decide', $v) }}" class="mt-4 flex flex-wrap items-end gap-2 border-t border-ink/5 pt-4">
                        @csrf @method('PUT')
                        <label class="text-xs text-mist">서류로 확인한 영업형태
                            <select name="biz_type" class="field mt-1 block !py-2" required>
                                <option value="">고르기</option>
                                @foreach ($bizTypes as $key => $t)
                                    <option value="{{ $key }}" @selected($v->liquor_biz_type === $key)>{{ $t['label'] }}{{ $t['allowed'] ? '' : ' · 원칙적 불가' }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="min-w-[16rem] flex-1 text-xs text-mist">메모 (확인한 서류·일자, 반려 사유)
                            <input name="memo" class="field mt-1 block w-full !py-2" maxlength="255" value="{{ $v->liquor_memo }}">
                        </label>
                        <button name="decision" value="approved" class="btn-ink !px-4 !py-2 text-sm">승인</button>
                        <button name="decision" value="rejected" class="btn-ghost !px-4 !py-2 text-sm text-signal"
                            @if ($v->alcohol_products_count) onclick="return confirm('반려하면 이 업체 매장의 주류 상품 {{ $v->alcohol_products_count }}개는 주문을 받지 않아요. 진행할까요?')" @endif>반려</button>
                    </form>
                </article>
            @endforeach
        </div>
        <div class="mt-4">{{ $vendors->links() }}</div>
    @endif
@endsection
