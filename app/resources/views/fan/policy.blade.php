@extends('layouts.fan')
@php($titles = ['terms' => '이용약관', 'privacy' => '개인정보 처리방침', 'finance' => '전자금융거래 이용약관', 'refund' => '취소 · 환불 규정'])
@section('title', $titles[$doc])

@section('content')
    <nav class="mt-2 flex flex-wrap gap-1.5 text-xs font-semibold" aria-label="약관">
        @foreach ($titles as $k => $t)
            <a href="{{ route('policy', $k) }}" @class(['chip', 'bg-ink text-paper' => $doc === $k, 'bg-white ring-1 ring-ink/10' => $doc !== $k])>{{ $t }}</a>
        @endforeach
    </nav>
    <h1 class="mt-5 text-2xl font-black">{{ $titles[$doc] }}</h1>
    <p class="mt-1 text-xs text-mist">시행 2026-11-15 · 개발용 초안 — 실서비스 전 법무 검토 필요</p>

    <article class="mt-6 space-y-5 rounded-3xl bg-white p-5 text-sm leading-relaxed ring-1 ring-ink/5">
        @switch($doc)
            @case('terms')
                <section><h2 class="font-bold">제1조 (회사의 지위)</h2>
                    <p class="mt-1">회사는 <b>공연 좌석 티켓</b>을 직접 판매하며, <b>공연장 매장 상품</b>에 대해서는 통신판매중개자로서 거래 당사자가 아닙니다. 매장 상품의 정보·품질·수령에 관한 책임은 각 판매자(입점업체)에게 있으며, 판매자 정보는 매장 화면 하단에서 확인할 수 있습니다.</p></section>
                <section><h2 class="font-bold">제2조 (주문과 수령)</h2>
                    <p class="mt-1">매장 상품은 배송 없이 공연장 매장에서 픽업번호로 직접 수령합니다. 주문 가능 수량은 매장의 시간대별 처리량에 따라 마감될 수 있습니다.</p></section>
                <section><h2 class="font-bold">제3조 (주류)</h2>
                    <p class="mt-1">주류는 만 19세 이상만 주문할 수 있고, 수령 시 실물 신분증으로 나이를 확인합니다. 확인되지 않으면 주류를 받을 수 없으며 해당 주문은 환불됩니다.</p></section>
                <section><h2 class="font-bold">제4조 (입점 판매자)</h2>
                    <p class="mt-1">판매자는 판매금액의 일정 비율(기본 8.5%, 부가세 별도)을 수수료로 내며, 수령완료된 주문을 기준으로 정해진 주기에 정산받습니다. 취소·환불된 주문에는 수수료가 없고, 정산 후 환불된 금액은 다음 정산에서 공제됩니다. 판매자는 정산서 발행일부터 30일 안에 이의를 제기할 수 있습니다.</p></section>
                @break
            @case('privacy')
                <section><h2 class="font-bold">수집 항목 · 목적</h2>
                    <p class="mt-1">예매자 이름·휴대폰 번호(티켓 발권·본인 확인·공연 변경 안내), 매장 주문 시 휴대폰 번호(주문 안내).</p></section>
                <section><h2 class="font-bold">제공 · 위탁</h2>
                    <p class="mt-1">매장 주문 이행을 위해 판매자에게 픽업번호·주문 상품·좌석 정보를 제공합니다. 휴대폰 번호는 가려서 표시합니다. 결제는 결제대행사에 위탁합니다.</p></section>
                <section><h2 class="font-bold">보유 기간</h2>
                    <p class="mt-1">전자상거래법에 따라 계약·청약철회 기록 5년, 대금결제·재화 공급 기록 5년, 소비자 불만·분쟁 처리 기록 3년 보관 후 파기합니다.</p></section>
                @break
            @case('finance')
                <section><h2 class="font-bold">결제</h2>
                    <p class="mt-1">결제는 결제대행사를 통해 이루어지며, 회사는 카드번호 등 결제 정보를 저장하지 않습니다.</p></section>
                <section><h2 class="font-bold">오류 정정</h2>
                    <p class="mt-1">결제에 오류가 있으면 고객센터로 정정을 요청할 수 있으며, 회사는 2주 안에 조사·처리 결과를 알립니다.</p></section>
                @break
            @default
                <section><h2 class="font-bold">좌석 티켓 (회사 직접 판매)</h2>
                    <table class="mt-2 w-full text-left">
                        <thead class="text-xs text-mist"><tr><th class="py-1">취소 시점</th><th class="py-1 text-right">취소 수수료</th></tr></thead>
                        <tbody class="divide-y divide-ink/5">
                            <tr><td class="py-1.5">관람일 10일 전까지</td><td class="py-1.5 text-right">없음</td></tr>
                            <tr><td class="py-1.5">관람일 9~7일 전</td><td class="py-1.5 text-right">티켓 금액의 10%</td></tr>
                            <tr><td class="py-1.5">관람일 6~3일 전</td><td class="py-1.5 text-right">20%</td></tr>
                            <tr><td class="py-1.5">관람일 2~1일 전</td><td class="py-1.5 text-right">30%</td></tr>
                            <tr><td class="py-1.5">관람 당일</td><td class="py-1.5 text-right">취소 불가</td></tr>
                        </tbody>
                    </table>
                    <p class="mt-2 text-mist">공연이 취소되거나 회사 사정으로 취소하는 경우 전액 환불합니다.</p></section>
                <section><h2 class="font-bold">매장 주문</h2>
                    <p class="mt-1">매장이 준비를 시작하기 전(결제완료 상태)에는 앱에서 바로 취소할 수 있고 전액 환불됩니다. 준비가 시작된 뒤에는 매장에 문의해 주세요. 판매자는 상품 하자 등 정당한 사유가 있는 취소 요청을 거부할 수 없습니다.</p></section>
        @endswitch
    </article>
@endsection
