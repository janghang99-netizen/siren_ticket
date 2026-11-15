<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>입점 심사 · SIREN</title>
    <link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-dvh place-items-center bg-ink px-4">
    <div class="w-full max-w-md rounded-[28px] bg-paper p-7">
        <p class="font-display text-xl font-black">SIREN</p>
        <p class="mt-4 text-sm text-mist">{{ $vendor->name }}</p>
        @switch($vendor->state)
            @case('pending')
                <h1 class="mt-1 text-2xl font-black">입점 심사 중이에요</h1>
                <p class="mt-2 text-sm">제출한 서류를 운영사가 확인하고 있어요. 승인되면 이 계정으로 매장 주문·상품·정산을 관리할 수 있어요.</p>
                @break
            @case('rejected')
                <h1 class="mt-1 text-2xl font-black text-signal">입점이 반려됐어요</h1>
                <p class="mt-3 rounded-2xl bg-signal/10 px-4 py-3 text-sm">사유: {{ $vendor->state_reason }}</p>
                <p class="mt-2 text-sm text-mist">사유를 보완해 운영사에 다시 문의해 주세요.</p>
                @break
            @default
                <h1 class="mt-1 text-2xl font-black text-signal">이용이 정지됐어요</h1>
                <p class="mt-3 rounded-2xl bg-signal/10 px-4 py-3 text-sm">사유: {{ $vendor->state_reason }}</p>
                <p class="mt-2 text-sm text-mist">정지 중에는 매장이 주문을 받지 않아요. 이미 확정된 정산은 그대로 지급돼요.</p>
        @endswitch
        <form method="POST" action="{{ route('logout') }}" class="mt-6">@csrf<button class="btn-ghost w-full">로그아웃</button></form>
    </div>
</body>
</html>
