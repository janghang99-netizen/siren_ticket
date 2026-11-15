@php
    $methods = ['pos_card' => '카드', 'pos_cash' => '현금', 'pos_transfer' => '계좌이체', 'card' => '온라인 결제'];
    $dine = $order->items->pluck('dine_option')->filter()->unique()->implode('·') ?: '포장';
    $paper = $width;                       // 용지 폭 (mm)
    $inner = $width === 58 ? 48 : 72;      // 인쇄 가능 폭 (mm)
@endphp
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <title>{{ $kind === 'kitchen' ? '주방 주문서' : '번호표' }} {{ $order->pickup_code }}</title>
    {{-- 영수증 프린터(80mm/58mm 감열지)용: 흑백, 기본 글꼴, 여백 없음, 길이는 내용만큼 --}}
    <style>
        @page { size: {{ $paper }}mm auto; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; color: #000; }
        body { width: {{ $inner }}mm; margin: 0 auto; padding: 3mm 0 6mm;
               font-family: "Malgun Gothic", "Apple SD Gothic Neo", "Noto Sans KR", sans-serif; font-size: {{ $width === 58 ? 11 : 12 }}px; line-height: 1.35; }
        .c { text-align: center; }
        .store { font-size: 1.25em; font-weight: 800; }
        .label { font-size: .95em; margin-top: 2mm; }
        .code { font-size: {{ $width === 58 ? 44 : 60 }}px; font-weight: 900; letter-spacing: .04em; line-height: 1; margin: 1mm 0 2mm; font-variant-numeric: tabular-nums; }
        .tag { display: inline-block; border: 2px solid #000; padding: .4mm 2.5mm; font-weight: 800; font-size: 1.1em; }
        .alert { border: 2px solid #000; padding: 1.5mm; font-weight: 800; text-align: center; margin: 2mm 0; }
        hr { border: 0; border-top: 1px dashed #000; margin: 2.5mm 0; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: .5mm 0; }
        td.q { width: 9mm; text-align: right; font-weight: 800; }
        td.p { width: 18mm; text-align: right; font-variant-numeric: tabular-nums; }
        .kitchen td { font-size: 1.35em; font-weight: 700; }
        .kitchen td.q { width: 12mm; }
        .total { display: flex; justify-content: space-between; font-weight: 800; font-size: 1.15em; }
        .small { font-size: .85em; }
        .reprint { border: 1px solid #000; padding: 0 1.5mm; font-size: .85em; font-weight: 700; }
        @media screen { body { margin: 8mm auto; box-shadow: 0 0 0 1px #ddd; padding: 4mm; width: {{ $paper }}mm; } }
    </style>
</head>
<body class="{{ $kind }}">
@if ($kind === 'kitchen')
    {{-- 주방 주문서: 번호와 품목만 크게 --}}
    <div class="c">
        <div class="store">주방 · {{ $order->store->name }} @if ($reprint)<span class="reprint">재출력</span>@endif</div>
        <div class="code">{{ $order->pickup_code }}</div>
        <span class="tag">{{ $dine }}</span>
    </div>
    <hr>
    <table>
        @foreach ($order->items as $i)
            <tr><td>{{ $i->name }}@if ($i->option_name)<br><span class="small">└ {{ $i->option_name }}</span>@endif</td><td class="q">×{{ $i->qty }}</td></tr>
        @endforeach
    </table>
    @if ($order->requires_id_check)
        <div class="alert">주류 · 건네기 전 신분증 확인</div>
    @endif
    <hr>
    <div class="small c">{{ $order->created_at->format('m.d H:i') }} · {{ $order->channel === 'pos' ? '현장판매' : '미리 주문' }} · {{ $order->order_no }}</div>
@else
    {{-- 손님 번호표 --}}
    <div class="c">
        <div class="store">{{ $order->store->name }}</div>
        <div class="label">픽업 번호 @if ($reprint)<span class="reprint">재출력</span>@endif</div>
        <div class="code">{{ $order->pickup_code }}</div>
        <span class="tag">{{ $dine }}</span>
    </div>
    <hr>
    <table>
        @foreach ($order->items as $i)
            <tr>
                <td>{{ $i->name }}@if ($i->option_name)<br><span class="small">└ {{ $i->option_name }}</span>@endif</td>
                <td class="q">{{ $i->qty }}</td>
                <td class="p">{{ number_format($i->unit_price * $i->qty) }}</td>
            </tr>
        @endforeach
    </table>
    <hr>
    <div class="total"><span>합계</span><span>{{ number_format($order->total_amount) }}원</span></div>
    <div class="small">결제 {{ $methods[$order->pay_method] ?? $order->pay_method }}@if ($order->card_approval_no) · 승인 {{ $order->card_approval_no }}@endif</div>
    @if ($order->requires_id_check)
        <div class="alert">주류 · 받으실 때 신분증을 보여 주세요</div>
    @endif
    <hr>
    <div class="c">
        <b>번호가 전광판에 뜨면 받아 가세요</b><br>
        <span class="small">이 번호표를 보여 주세요 · {{ $order->created_at->format('Y.m.d H:i') }}</span><br>
        <span class="small">주문번호 {{ $order->order_no }}</span>
    </div>
    <div class="small c" style="margin-top:2mm">SIREN · 공연장 매장 주문</div>
@endif
@if ($auto)
    <script>window.addEventListener('load', () => { window.print(); setTimeout(() => window.close(), 500) })</script>
@endif
</body>
</html>
