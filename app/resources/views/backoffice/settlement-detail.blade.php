{{-- 정산서 상세 (운영사·업체 공용). 조치(이의제기·답변)는 @push('settlement-actions') 로 붙인다 --}}
<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
    @foreach ([
        ['매출', $settlement->gross_amount, '', false],
        ['수수료', $settlement->commission_amount, '−', false],
        ['수수료 부가세', $settlement->commission_vat, '−', false],
        ['공제', $settlement->deduction_amount, '−', false],
        ['지급액', $settlement->payout_amount, '', true],
    ] as [$label, $value, $sign, $strong])
        <div @class(['rounded-3xl p-5', 'bg-ink text-paper' => $strong, 'bg-white ring-1 ring-ink/5' => ! $strong])>
            <p @class(['text-xs font-semibold', 'text-paper/60' => $strong, 'text-mist' => ! $strong])>{{ $label }}</p>
            <p class="num mt-1 text-2xl font-black">{{ $value ? $sign : '' }}{{ number_format($value) }}<span class="font-sans text-sm font-semibold">원</span></p>
        </div>
    @endforeach
</div>

<dl class="mt-4 grid gap-x-8 gap-y-2 rounded-3xl bg-white p-5 text-sm ring-1 ring-ink/5 sm:grid-cols-2">
    <div class="flex justify-between gap-3"><dt class="text-mist">정산 기간</dt><dd class="num">{{ $settlement->period_start->format('Y.m.d') }} – {{ $settlement->period_end->format('Y.m.d') }}</dd></div>
    <div class="flex justify-between gap-3"><dt class="text-mist">주문</dt><dd class="num">{{ $settlement->order_count }}건</dd></div>
    <div class="flex justify-between gap-3"><dt class="text-mist">지급 예정일</dt>
        <dd class="num">{{ $settlement->payout_due_on?->format('Y.m.d') ?? '—' }}
            @if ($settlement->overdueDays())<span class="chip ml-1 bg-signal/10 text-signal">지연 {{ $settlement->overdueDays() }}일</span>@endif</dd></div>
    <div class="flex justify-between gap-3"><dt class="text-mist">상태</dt><dd class="font-semibold">{{ $settlement->statusLabel() }}@if ($settlement->paid_at) <span class="num text-mist">{{ $settlement->paid_at->format('m.d H:i') }}</span>@endif</dd></div>
    <div class="flex justify-between gap-3"><dt class="text-mist">수수료 세금계산서</dt><dd>{{ $settlement->tax_invoice_issued_at ? '발행 완료 '.$settlement->tax_invoice_issued_at->format('m.d') : '발행 전' }} <span class="num text-xs text-mist">공급가 {{ number_format($settlement->commission_amount) }} · 세액 {{ number_format($settlement->commission_vat) }}</span></dd></div>
    <div class="flex justify-between gap-3"><dt class="text-mist">같은 기간 환불</dt><dd><span class="num">{{ $settlement->refund_count }}</span>건 · <span class="num">{{ number_format($settlement->refund_amount) }}</span>원 <span class="text-xs text-mist">(매출에 포함 안 됨)</span></dd></div>
    <div class="flex justify-between gap-3 sm:col-span-2"><dt class="text-mist">입금 계좌</dt><dd>{{ $settlement->vendor->bank_name ?? '—' }} {{ $settlement->vendor->bank_account }} {{ $settlement->vendor->account_holder }}</dd></div>
</dl>

@if ($settlement->adjustments->isNotEmpty())
    <section class="mt-4 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
        <h2 class="font-bold">공제 내역</h2>
        <ul class="mt-2 space-y-1 text-sm">
            @foreach ($settlement->adjustments as $a)
                <li class="flex justify-between gap-3"><span>{{ $a->reason }}</span><span class="num font-bold text-signal">−{{ number_format($a->amount) }}</span></li>
            @endforeach
        </ul>
    </section>
@endif

@if ($settlement->disputed_at)
    <section class="mt-4 rounded-3xl bg-white p-5 ring-1 ring-signal/30">
        <h2 class="font-bold">이의제기 <span class="num text-xs font-normal text-mist">{{ $settlement->disputed_at->format('Y.m.d H:i') }}</span></h2>
        <p class="mt-2 whitespace-pre-line text-sm">{{ $settlement->dispute_message }}</p>
        @if ($settlement->dispute_replied_at)
            <div class="mt-3 rounded-2xl bg-paper p-4">
                <p class="text-xs font-bold text-mist">운영사 답변 · <span class="num">{{ $settlement->dispute_replied_at->format('Y.m.d H:i') }}</span></p>
                <p class="mt-1 whitespace-pre-line text-sm">{{ $settlement->dispute_reply }}</p>
            </div>
        @endif
    </section>
@endif

@stack('settlement-actions')

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
            @php($orderRoute = auth()->user()->isSuperAdmin() ? 'admin.orders.show' : 'partner.orders.show')
            @foreach ($orders as $o)
                <tr data-href="{{ route($orderRoute, $o) }}">
                    <td class="num px-5 py-2.5 text-xs">{{ $o->picked_up_at?->format('m.d H:i') }}</td>
                    <td class="px-3 py-2.5">{{ $o->store->name }}</td>
                    <td class="px-3 py-2.5"><a href="{{ route($orderRoute, $o) }}" class="row-link num">{{ $o->pickup_code }}</a> <span class="text-mist">{{ $o->items->map(fn ($i) => $i->name.' ×'.$i->qty)->join(', ') }}</span></td>
                    <td class="num px-3 py-2.5 text-right">{{ number_format($o->total_amount) }}</td>
                    <td class="num px-3 py-2.5 text-right text-mist">{{ rtrim(rtrim($o->commission_rate, '0'), '.') }}%</td>
                    <td class="num px-5 py-2.5 text-right">{{ number_format(round($o->total_amount * $o->commission_rate / 100)) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
