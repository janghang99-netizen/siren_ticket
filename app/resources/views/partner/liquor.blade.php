@extends('layouts.backoffice')
@section('title', '주류판매 자격')
@section('eyebrow', '운영사 승인을 받아야 주류 상품을 팔 수 있어요')

@section('content')
    @php($st = $vendor->liquor_status)
    <section @class(['mb-6 rounded-3xl p-6 text-sm',
        'bg-ink text-paper' => $st === 'approved',
        'bg-pick' => $st === 'pending',
        'bg-signal/10 text-signal' => $st === 'rejected',
        'bg-white ring-1 ring-ink/5' => $st === null])>
        <p class="text-xs opacity-70">지금 상태</p>
        <p class="mt-1 text-2xl font-black">{{ $vendor->liquorStatusLabel() }}</p>
        <p class="mt-1">
            @switch($st)
                @case('approved') 주류 상품을 등록·판매할 수 있어요. 영업형태: {{ $vendor->liquorBizLabel() }} @break
                @case('pending') 운영사가 서류를 확인하고 있어요. 승인되면 상품 화면에서 ‘주류’를 켤 수 있어요. @break
                @case('rejected') 반려됐어요{{ $vendor->liquor_memo ? ' — '.$vendor->liquor_memo : '' }}. 아래에서 서류를 고쳐 다시 신청해 주세요. @break
                @default 아직 신청하지 않았어요. 주류를 팔려면 아래에서 신청해 주세요.
            @endswitch
        </p>
    </section>

    <section class="mb-6 rounded-3xl bg-white p-6 text-sm leading-relaxed ring-1 ring-ink/5">
        <h2 class="font-bold">왜 승인이 필요한가요</h2>
        <p class="mt-1 text-ink/80">주류는 매장의 영업 등록형태에 따라 팔 수 있는지가 갈려요. 운영사가 영업신고증으로 확인한 업체만 주류를 등록할 수 있어요.</p>
        <ul class="mt-3 space-y-1.5">
            @foreach ($bizTypes as $t)
                <li class="flex gap-2">
                    <span @class(['chip shrink-0', 'bg-stick/10 text-stick' => $t['allowed'], 'bg-signal/10 text-signal' => ! $t['allowed']])>{{ $t['allowed'] ? '가능' : '불가' }}</span>
                    <span><b>{{ $t['label'] }}</b> — {{ $t['guide'] }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    @if ($st !== 'approved')
        <form method="POST" action="{{ route('partner.liquor.request') }}" enctype="multipart/form-data" class="max-w-xl space-y-4 rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            @csrf
            <h2 class="font-bold">{{ $st === null ? '신청하기' : '다시 신청하기' }}</h2>
            <div>
                <label for="biz_type" class="label">영업형태 (영업신고증에 적힌 업종)</label>
                <select id="biz_type" name="biz_type" class="field" required>
                    <option value="">고르기</option>
                    @foreach ($bizTypes as $key => $t)
                        <option value="{{ $key }}" @selected(old('biz_type', $vendor->liquor_biz_type) === $key)>{{ $t['label'] }}</option>
                    @endforeach
                </select>
                @error('biz_type')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="liquor_license_no" class="label">주류판매(의제주류판매업) 신고번호</label>
                <input id="liquor_license_no" name="liquor_license_no" class="field num" maxlength="40" required value="{{ old('liquor_license_no', $vendor->liquor_license_no) }}">
                @error('liquor_license_no')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="permit" class="label">영업신고증 또는 주류판매업 면허증 사본</label>
                <input id="permit" name="permit" type="file" accept=".jpg,.jpeg,.png,.pdf" class="field">
                <p class="mt-1 text-xs text-mist">JPG·PNG·PDF, 10MB 까지.{{ ! empty($vendor->documents['liquor_permit']) ? ' 이미 낸 파일이 있어요 — 새로 올리면 바뀌어요.' : '' }} 사업자등록증은 입점 때 낸 것을 함께 확인해요.</p>
                @error('permit')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <button class="btn-ink">신청하기</button>
        </form>
    @endif
@endsection
