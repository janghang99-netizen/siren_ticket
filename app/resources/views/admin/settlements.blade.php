@extends('layouts.backoffice')
@section('title', '정산')
@section('eyebrow', '입점업체')

@section('actions')
    <a href="{{ route('admin.settlements.report') }}" class="btn-ghost !py-2.5 text-sm">판매자 정산 보고서</a>
@endsection

@section('content')
    <div class="mb-6 grid gap-4 xl:grid-cols-[1fr_1.4fr]">
        <div class="rounded-3xl bg-ink p-6 text-paper">
            <p class="text-xs font-semibold text-paper/60">정산 대기 (수령완료, 정산서 없음)</p>
            <p class="num mt-1 text-3xl font-black">{{ number_format($unsettledAmount) }}<span class="font-sans text-base">원</span></p>
            <p class="mt-1 text-sm text-paper/60"><span class="num">{{ number_format($unsettledCount) }}</span>건{{ $oldest ? ' · 가장 오래된 수령 '.\Illuminate\Support\Carbon::parse($oldest)->format('m.d') : '' }}</p>
        </div>

        <form method="POST" action="{{ route('admin.settlements.generate') }}" class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            @csrf
            <h2 class="font-bold">정산서 만들기 (전체 업체)</h2>
            <p class="mt-1 text-sm text-mist">기간 안에 수령완료된 주문을 업체별로 묶어요. 묶인 주문은 정산완료로 바뀌고 다시 묶이지 않아요.</p>
            <div class="mt-4 flex flex-wrap items-end gap-3">
                <div>
                    <label for="from" class="label">수령일 시작</label>
                    <input id="from" type="date" name="from" class="field num !py-2.5" value="{{ old('from', now()->startOfWeek()->toDateString()) }}" required>
                </div>
                <div>
                    <label for="to" class="label">끝</label>
                    <input id="to" type="date" name="to" class="field num !py-2.5" value="{{ old('to', now()->toDateString()) }}" required>
                </div>
                <button class="btn-ink !py-3" onclick="return confirm('이 기간의 정산서를 만들까요? 만든 뒤에는 주문이 정산완료로 바뀌어요.')">정산서 만들기</button>
            </div>
            @error('from')<p class="mt-2 text-sm text-signal">{{ $message }}</p>@enderror
        </form>
    </div>

    {{-- 원샷 판매자 정산 대기목록 (원본 다담다 go_money.php). 정산금액·수수료·세금계산서는 페이레터를 불러온 업체면 카드결제 기준으로 표시 --}}
    <section class="mb-8">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-black">원샷 판매자 정산 대기목록</h2>
                <p class="text-sm text-mist">업체별 수령완료 주문(주문일 기준). [정산완료]를 누르면 그 업체 주문을 정산서로 묶어요 — 송금한 뒤에 누르세요.</p>
            </div>
            <form method="GET" class="flex flex-wrap items-end gap-2">
                <div><label class="label" for="s_date">주문일 시작 (부분정산)</label><input id="s_date" type="date" name="s_date" value="{{ $sDate }}" class="field num !py-2"></div>
                <div><label class="label" for="e_date">끝</label><input id="e_date" type="date" name="e_date" value="{{ $eDate }}" class="field num !py-2"></div>
                <button class="btn-ghost !py-2.5 text-sm">주문건 검색</button>
                @if ($pending->isNotEmpty())
                    <a href="{{ route('admin.settlements.oneshot.export', array_filter(['s_date' => $sDate, 'e_date' => $eDate])) }}" class="btn-ghost !py-2.5 text-sm">엑셀 다운로드</a>
                @endif
            </form>
        </div>

        @if ($notDue->isNotEmpty())
            {{-- 정산예정일이 아직 안 된 수령완료 주문 (원본 sp_notdue_html) — 대상이 없어도 보여 준다 --}}
            <div class="mb-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-6 text-ink">
                <b>정산예정일이 아직 안 된 수령완료 주문</b> — 아래 목록(정산 대상)에서 빠져 있고, 정산예정일이 되면 자동으로 올라와요.<br>
                @foreach ($notDue as $n)
                    · {{ $n['vendor']->name }} : <span class="num">{{ number_format($n['count']) }}</span>줄 · <span class="num">{{ number_format($n['amount']) }}</span>원 (가장 빠른 정산예정일 <span class="num">{{ $n['first_due'] }}</span>)<br>
                @endforeach
                <span class="text-mist">정산예정일 = 주문일 + 기본설정 "상품판매 정산예정일"({{ $dueDays }}일). 늦게 잡히는 카드 결제가 반영될 시간을 두기 위함이에요.</span>
            </div>
        @endif

        @if ($pending->isEmpty())
            <div class="rounded-3xl bg-white px-6 py-10 text-center text-sm text-mist ring-1 ring-ink/5">업체에게 정산할 내역이 없어요.</div>
        @else
            @php
                $tot = ['gross' => 0, 'payout' => 0, 'fee' => 0, 'sup' => 0, 'vat' => 0, 'mileage' => 0];
            @endphp
            <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
                <table class="w-full min-w-[1080px] text-sm">
                    <thead class="border-b border-ink/5 text-left text-xs text-mist">
                        <tr>
                            <th class="px-5 py-3 font-semibold">업체</th>
                            <th class="px-3 py-3 font-semibold">정산 계좌</th>
                            <th class="px-3 py-3 text-right font-semibold">총금액</th>
                            <th class="px-3 py-3 text-right font-semibold">적립금·업체쿠폰</th>
                            <th class="px-3 py-3 text-right font-semibold">수수료 (부가세 포함)</th>
                            <th class="px-3 py-3 text-right font-semibold">정산금액</th>
                            <th class="px-3 py-3 text-right font-semibold">발행할 세금계산서<br><span class="font-normal">(수수료 기준 · 회사→업체)</span></th>
                            <th class="px-3 py-3 text-right font-semibold">건수</th>
                            <th class="px-5 py-3 font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink/5">
                        @foreach ($pending as $r)
                            @php
                                $c = $r['card'];
                                $gross = $c['ok'] ? $c['card'] : $r['gross'];
                                $sup = $c['ok'] ? $c['fee'] : $r['commission'];
                                $vat = $c['ok'] ? $c['vat'] : $r['vat'];
                                $payout = $c['ok'] ? $c['pay'] : $r['payout'];
                                $tot['gross'] += $gross; $tot['payout'] += $payout; $tot['fee'] += $sup + $vat; $tot['sup'] += $sup; $tot['vat'] += $vat; $tot['mileage'] += $r['mileage'] + $r['coupon'];
                                $v = $r['vendor'];
                            @endphp
                            <tr class="align-top">
                                <td class="px-5 py-3">
                                    <a href="{{ route('admin.vendors.show', $v) }}" class="font-semibold underline-offset-4 hover:underline">{{ $v->name }}</a>
                                    <p class="num mt-1 text-xs text-mist">주문일 {{ $r['first'] }} ~ {{ $r['last'] }}</p>
                                </td>
                                <td class="px-3 py-3 text-xs">{{ $v->bank_name ?: '-' }}<br><span class="num">{{ $v->bank_account }}</span><br>{{ $v->account_holder }}</td>
                                <td class="num px-3 py-3 text-right">
                                    {{ number_format($gross) }}
                                    @if ($c['ok'])<p class="text-xs text-mist">카드결제 {{ number_format($c['n']) }}줄<br>주문 기준 {{ number_format($r['gross']) }}</p>@endif
                                </td>
                                <td class="num px-3 py-3 text-right">{{ number_format($r['mileage'] + $r['coupon']) }}</td>
                                <td class="num px-3 py-3 text-right">{{ number_format($sup + $vat) }}</td>
                                <td class="num px-3 py-3 text-right">
                                    <b class="text-base">{{ number_format($payout) }}</b>
                                    @include('admin.partials.card-note', ['c' => $c, 'vendor' => $v, 'old' => $r['payout'], 'src' => 'pending'])
                                </td>
                                <td class="num px-3 py-3 text-right text-xs leading-6">공급가액 <b>{{ number_format($sup) }}</b><br>부가세 <b>{{ number_format($vat) }}</b><br>합계 <b>{{ number_format($sup + $vat) }}</b></td>
                                <td class="num px-3 py-3 text-right">{{ number_format($r['count']) }}</td>
                                <td class="px-5 py-3">
                                    <form method="POST" action="{{ route('admin.settlements.oneshot', $v) }}"
                                          data-msg="{{ ($sDate || $eDate ? '['.($sDate ?: '처음').' ~ '.($eDate ?: '오늘').'] 주문건에 대해서'."\n" : '').$v->name."에 정산(송금)을 해 주셨나요?\n정산서를 만들고 주문을 정산완료로 바꿉니다." }}"
                                          onsubmit="return confirm(this.dataset.msg)">
                                        @csrf
                                        <input type="hidden" name="s_date" value="{{ $sDate }}"><input type="hidden" name="e_date" value="{{ $eDate }}">
                                        <button class="btn-ink !px-4 !py-2 text-sm">정산완료</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t border-ink/10 bg-ink/[0.03] font-bold">
                        <tr>
                            <td class="px-5 py-3" colspan="2">합계</td>
                            <td class="num px-3 py-3 text-right">{{ number_format($tot['gross']) }}</td>
                            <td class="num px-3 py-3 text-right">{{ number_format($tot['mileage']) }}</td>
                            <td class="num px-3 py-3 text-right">{{ number_format($tot['fee']) }}</td>
                            <td class="num px-3 py-3 text-right">{{ number_format($tot['payout']) }}</td>
                            <td class="num px-3 py-3 text-right text-xs">공급가액 {{ number_format($tot['sup']) }} + 부가세 {{ number_format($tot['vat']) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
        <p class="mt-2 text-xs text-mist">
            정산금액 = 총금액 − 적립금·업체쿠폰 − 수수료 − 부가세. 페이레터 카드 내역을 불러온 업체는 카드결제(승인 − 취소) − 이용료 {{ \App\Services\SettlementReport::pct((float) config('siren.card_settle_rate')) }}% − 부가세로 표시해요(현금·계좌이체는 매장 직접 수령이라 빠짐). 표시만 바뀌고 [정산완료] 처리는 같아요.
            @if ($importedPending)<br>원본 다담다에서 옮겨 온 수령완료 주문 {{ number_format($importedPending) }}건은 원본 정산 기록을 따르므로 여기서 정산하지 않아요.@endif
        </p>
    </section>

    {{-- 원샷 정산 완료목록 (원본 jungsan_list.php) --}}
    <h2 class="mb-3 text-lg font-black">정산 완료목록</h2>
    @include('backoffice.settlement-list', ['settlements' => $settlements, 'showVendor' => true, 'route' => 'admin.settlements.show', 'cards' => $cards])
@endsection
