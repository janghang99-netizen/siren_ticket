<!doctype html>
{{-- 사이트 전체 준비중 화면 (php artisan down --render="errors::503"). 점검 중엔 앱이 안 뜨므로 CSS를 이 파일 안에 둔다 --}}
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0E0C15">
    <title>서비스 준비중 · SIREN Ticket</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    <style>
        :root { --ink: #0E0C15; --paper: #F6F4EF; --mist: #8A8794; }
        * { box-sizing: border-box; margin: 0; }
        html, body { height: 100%; }
        body {
            display: grid; place-items: center; padding: 24px 16px;
            background: var(--ink); color: var(--paper);
            font-family: "Pretendard Variable", Pretendard, system-ui, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        main { width: 100%; max-width: 420px; text-align: center; }
        .brand { font-size: 13px; font-weight: 700; letter-spacing: .18em; color: var(--mist); }
        h1 { margin-top: 20px; font-size: clamp(28px, 7vw, 36px); font-weight: 800; line-height: 1.25; letter-spacing: -.02em; }
        p { margin-top: 14px; font-size: 15px; line-height: 1.65; color: rgba(246, 244, 239, .72); }
        .bar { width: 40px; height: 3px; margin: 28px auto 0; border-radius: 2px; background: rgba(246, 244, 239, .2); }
    </style>
</head>
<body>
    <main>
        <div class="brand">SIREN TICKET</div>
        <h1>서비스 준비중입니다</h1>
        <p>더 나은 서비스를 위해 준비하고 있어요.<br>조금만 기다려 주세요.</p>
        <div class="bar" aria-hidden="true"></div>
    </main>
</body>
</html>
