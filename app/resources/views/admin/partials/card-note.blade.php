{{-- 카드결제 기준 정산금액 설명 (원본 다담다 sp_card_note). $c = PgSettlementService::cardSettle(), $old = 주문 기준 정산금액, $src = pending|done --}}
@if ($c['ok'])
    <div class="mt-2 border-t border-dotted border-ink/15 pt-1.5 text-right text-[11px] font-normal leading-5 text-mist">
        카드결제 기준 · 승인일 <span class="num">{{ $c['from'] }} ~ {{ $c['to'] }}</span><br>
        카드 <span class="num">{{ number_format($c['card']) }}</span> − 이용료 <span class="num">{{ number_format($c['fee']) }}</span> − 부가세 <span class="num">{{ number_format($c['vat']) }}</span><br>
        <span class="opacity-70">(주문 기준 <span class="num">{{ number_format($old) }}</span> · 현금·이체 포함)</span><br>
        @if ($c['short'])<b class="text-signal">{{ $c['unfinal'] }} 이후 카드 결제가 덜 반영됐을 수 있음 → [보고서]로 대사</b><br>@endif
        <a href="{{ route('admin.settlements.report', ['vendor' => $vendor->id, 'from' => $c['from'], 'to' => $c['to'], 'src' => $src]) }}"
           class="mt-1 inline-block rounded-md bg-ink px-2.5 py-1 font-bold text-paper">보고서</a>
    </div>
@endif
