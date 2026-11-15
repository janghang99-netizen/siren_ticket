{{-- 정산서 상세 (운영사·업체 공용) --}}
<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ([['매출', $settlement->gross_amount, false], ['수수료', $settlement->commission_amount, false], ['지급액', $settlement->payout_amount, true], ['주문 수', $settlement->order_count, false]] as [$label, $value, $strong])
        <div @class(['rounded-3xl p-5', 'bg-ink text-paper' => $strong, 'bg-white ring-1 ring-ink/5' => ! $strong])>
            <p @class(['text-xs font-semibold', 'text-paper/60' => $strong, 'text-mist' => ! $strong])>{{ $label }}</p>
            <p class="num mt-1 text-2xl font-black">{{ number_format($value) }}<span class="font-sans text-sm font-semibold">{{ $label === '주문 수' ? '건' : '원' }}</span></p>
        </div>
    @endforeach
</div>

<dl class="mt-4 grid gap-x-8 gap-y-2 rounded-3xl bg-white p-5 text-sm ring-1 ring-ink/5 sm:grid-cols-2">
    <div class="flex justify-between gap-3"><dt class="text-mist">정산 기간</dt><dd class="num">{{ $settlement->period_start->format('Y.m.d') }} – {{ $settlement->period_end->format('Y.m.d') }}</dd></div>
    <div class="flex justify-between gap-3"><dt class="text-mist">상태</dt><dd class="font-semibold">{{ $settlement->statusLabel() }}@if ($settlement->paid_at) <span class="num text-mist">{{ $settlement->paid_at->format('m.d H:i') }}</span>@endif</dd></div>
    <div class="flex justify-between gap-3"><dt class="text-mist">같은 기간 환불</dt><dd><span class="num">{{ $settlement->refund_count }}</span>건 · <span class="num">{{ number_format($settlement->refund_amount) }}</span>원 <span class="text-xs text-mist">(매출에 포함 안 됨)</span></dd></div>
    <div class="flex justify-between gap-3"><dt class="text-mist">입금 계좌</dt><dd>{{ $settlement->vendor->bank_name ?? '—' }} {{ $settlement->vendor->bank_account }} {{ $settlement->vendor->account_holder }}</dd></div>
</dl>

<h2 class="mb-3 mt-8 text-lg font-bold">포함된 주문 <span class="num text-mist">{{ $orders->count() }}</span></h2>
<div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
    <table class="w-full min-w-[640px] text-sm">
        <thead class="border-b border-ink/5 text-left text-xs text-mist">
            <tr>
                <th class="px-5 py-3 font-semibold">수령 일시</th>
                <th class="px-3 py-3 font-semibold">매장</th>
                <th class="px-3 py-3 font-semibold">주문</th>
                <th class="px-3 py-3 text-right font-semibold">금액</th>
                <th class="px-3 py-3 text-right font-semibold">수수료율</th>
                <th class="px-5 py-3 text-right font-semibold">수수료</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-ink/5">
            @foreach ($orders as $o)
                <tr>
                    <td class="num px-5 py-2.5 text-xs">{{ $o->picked_up_at?->format('m.d H:i') }}</td>
                    <td class="px-3 py-2.5">{{ $o->store->name }}</td>
                    <td class="px-3 py-2.5"><span class="num font-bold">{{ $o->pickup_code }}</span> <span class="text-mist">{{ $o->items->map(fn ($i) => $i->name.' ×'.$i->qty)->join(', ') }}</span></td>
                    <td class="num px-3 py-2.5 text-right">{{ number_format($o->total_amount) }}</td>
                    <td class="num px-3 py-2.5 text-right text-mist">{{ rtrim(rtrim($o->commission_rate, '0'), '.') }}%</td>
                    <td class="num px-5 py-2.5 text-right">{{ number_format(round($o->total_amount * $o->commission_rate / 100)) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
