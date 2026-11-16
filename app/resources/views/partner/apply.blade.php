<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>입점 신청 · {{ $site['brand_name'] }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php($cycle = \App\Models\Vendor::CYCLES[$settings['default_settlement_cycle']] ?? '2주마다')
<body class="min-h-dvh">
<div class="mx-auto max-w-3xl px-5 py-10">
    <a href="{{ route('home') }}" class="font-display text-lg font-black">{{ $site['brand_name'] }}</a>
    <h1 class="mt-4 text-3xl font-black">공연장 매장 입점 신청</h1>
    <p class="mt-2 text-mist">서울아레나 공연장 안에서 모바일 선주문·픽업으로 판매해요. 서류를 확인한 뒤 승인되면 매장을 열 수 있어요.</p>

    <dl class="mt-6 grid gap-3 sm:grid-cols-3">
        <div class="rounded-2xl bg-white p-4 ring-1 ring-ink/5"><dt class="text-xs text-mist">판매 수수료</dt><dd class="num mt-1 text-xl font-black">{{ rtrim(rtrim($settings['default_commission_rate'], '0'), '.') }}%<span class="font-sans text-xs font-semibold text-mist"> 부가세 별도</span></dd></div>
        <div class="rounded-2xl bg-white p-4 ring-1 ring-ink/5"><dt class="text-xs text-mist">정산</dt><dd class="mt-1 text-xl font-black">{{ $cycle }}</dd></div>
        <div class="rounded-2xl bg-white p-4 ring-1 ring-ink/5"><dt class="text-xs text-mist">정산 대상</dt><dd class="mt-1 text-sm font-bold">수령완료 주문 · 취소분 수수료 없음</dd></div>
    </dl>

    @if ($errors->any())
        <p role="alert" class="mt-6 rounded-2xl bg-signal/10 px-4 py-3 text-sm font-semibold text-signal">{{ $errors->first() }}</p>
    @endif

    <form method="POST" action="{{ route('partner.apply') }}" enctype="multipart/form-data" class="mt-6 space-y-6">
        @csrf
        <section class="space-y-4 rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <h2 class="font-bold">사업자 정보 <span class="text-xs font-normal text-mist">구매자에게 판매자 정보로 고지돼요</span></h2>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ([
                    'name' => ['상호', true, ''], 'representative' => ['대표자', true, ''],
                    'business_no' => ['사업자등록번호', true, '123-45-67890'], 'mail_order_no' => ['통신판매업 신고번호', true, '제2026-서울도봉-0000호'],
                    'liquor_license_no' => ['주류판매 신고번호 (주류를 팔 때만)', false, ''], 'contact_phone' => ['대표 연락처', true, '02-000-0000'],
                    'contact_email' => ['담당자 이메일', true, ''], 'address' => ['사업장 주소', true, ''],
                ] as $f => [$l, $req, $ph])
                    <div @class(['sm:col-span-2' => $f === 'address'])>
                        <label for="{{ $f }}" class="label">{{ $l }}</label>
                        <input id="{{ $f }}" name="{{ $f }}" value="{{ old($f) }}" class="field" placeholder="{{ $ph }}" @required($req) @if ($f === 'contact_email') type="email" @endif>
                        @error($f)<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>
        </section>

        <section class="space-y-4 rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <h2 class="font-bold">정산 계좌</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach (['bank_name' => '은행', 'bank_account' => '계좌번호', 'account_holder' => '예금주'] as $f => $l)
                    <div><label for="{{ $f }}" class="label">{{ $l }}</label><input id="{{ $f }}" name="{{ $f }}" value="{{ old($f) }}" class="field" required></div>
                @endforeach
            </div>
        </section>

        <section class="space-y-4 rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <h2 class="font-bold">제출 서류 <span class="text-xs font-normal text-mist">PDF·JPG·PNG, 각 5MB 이하 · 운영사만 열람해요</span></h2>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach (\App\Models\Vendor::DOCUMENTS as $type => $label)
                    <div>
                        <label for="doc-{{ $type }}" class="label">{{ $label }}</label>
                        <input id="doc-{{ $type }}" type="file" name="docs[{{ $type }}]" accept=".pdf,.jpg,.jpeg,.png" class="block w-full rounded-2xl bg-paper px-3 py-2.5 text-sm file:mr-3 file:rounded-full file:border-0 file:bg-ink file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-paper" required>
                        @error("docs.$type")<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>
        </section>

        <section class="space-y-4 rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <h2 class="font-bold">로그인 계정</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <div><label for="login_email" class="label">로그인 이메일</label><input id="login_email" type="email" name="login_email" value="{{ old('login_email') }}" class="field" autocomplete="username" required></div>
                <div><label for="login_password" class="label">비밀번호 (8자 이상)</label><input id="login_password" type="password" name="login_password" class="field" autocomplete="new-password" minlength="8" required></div>
                <div><label for="login_password_confirmation" class="label">비밀번호 확인</label><input id="login_password_confirmation" type="password" name="login_password_confirmation" class="field" autocomplete="new-password" required></div>
            </div>
        </section>

        <section class="space-y-3 rounded-3xl bg-white p-6 text-sm ring-1 ring-ink/5">
            <h2 class="font-bold">동의</h2>
            <details class="rounded-2xl bg-paper p-4">
                <summary class="cursor-pointer font-semibold">판매 금지 품목 · 주류 판매 조건</summary>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-mist">
                    <li>의약품·의료기기, 담배, 총포·도검, 청소년유해매체물, 위조품 등 온라인 판매가 금지·제한되는 상품은 팔 수 없어요.</li>
                    <li>주류는 주류판매 신고를 한 업체만, 배달 없이 매장 현장 수령으로만 팔 수 있어요. 수령할 때 실물 신분증으로 나이를 확인할 책임은 판매자에게 있어요.</li>
                    <li>위반하면 운영사가 사전 통지 없이 상품 판매를 중지하거나 계약을 해지할 수 있어요.</li>
                </ul>
            </details>
            <label class="flex items-start gap-2"><input type="checkbox" name="agree_prohibited" value="1" class="mt-1" @checked(old('agree_prohibited')) required> 판매 금지 품목과 주류 판매 조건을 확인했어요. (필수)</label>
            <label class="flex items-start gap-2"><input type="checkbox" name="agree_contract" value="1" class="mt-1" @checked(old('agree_contract')) required>
                <span>입점 판매자 약관(수수료 {{ rtrim(rtrim($settings['default_commission_rate'], '0'), '.') }}% 부가세 별도, {{ $cycle }} 정산, 정산 후 취소분 차기 공제, 정산 이의제기 30일)에 동의해요. (필수)
                    <a href="{{ route('policy', 'terms') }}" target="_blank" class="row-link">약관 보기</a></span></label>
        </section>

        <button class="btn-ink w-full text-base">입점 신청하기</button>
        <p class="text-center text-sm text-mist">이미 신청했나요? <a href="{{ route('login') }}" class="row-link">로그인해서 심사 상태 보기</a></p>
    </form>
</div>
</body>
</html>
