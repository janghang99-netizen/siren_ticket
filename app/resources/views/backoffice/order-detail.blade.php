{{-- 주문 상세 (운영사·업체 공용). 구매자 휴대폰은 마스킹 (E2). 오른쪽 아래에 @push('order-aside') 로 조치 버튼을 붙인다 --}}
@php($pay = ['card' => '카드', 'pos_cash' => '현장 현금', 'pos_transfer' => '현장 계좌이체', 'pos_card' => '현장 카드'][$order->pay_method] ?? $order->pay_method)
@php($timeline = array_filter([
    '주문' => $order->created_at,
    '결제' => $order->paid_at,
    '준비 시작' => $order->preparing_at,
    '준비 완료' => $order->ready_at,
    '수령' => $order->picked_up_at,
    '정산' => $order->settled_at,
    '취소' => $order->cancelled_at,
    '환불' => $order->refunded_at,
]))

<div class="grid gap-5 xl:grid-cols-[1fr_340px]">
    <div class="space-y-5">
        <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="eyebrow">픽업번호</p>
                    <p class="num text-5xl font-black leading-none">{{ $order->pickup_code }}</p>
                    <p class="num mt-2 text-xs text-mist">{{ $order->order_no }}</p>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <span @class(['chip text-sm',
                        'bg-pick' => $order->status->value === 4,
                        'bg-stick/10 text-stick' => in_array($order->status->value, [1, 2, 3]),
                        'bg-ink/10' => in_array($order->status->value, [5, 6]),
                        'bg-signal/10 text-signal' => in_array($order->status->value, [11, 14])])>{{ $order->status->label() }}</span>
                    @if ($order->channel === 'pos')<span class="chip bg-ink/10 text-sm">현장판매</span>@endif
                    @if ($order->requires_id_check)<span class="chip bg-signal text-sm text-white">주류 · 수령 시 신분증 확인</span>@endif
                </div>
            </div>

            <table class="mt-6 w-full text-sm">
                <thead class="text-left text-xs text-mist">
                    <tr><th class="py-2 font-semibold">상품</th><th class="py-2 text-right font-semibold">단가</th><th class="py-2 text-right font-semibold">수량</th><th class="py-2 text-right font-semibold">금액</th></tr>
                </thead>
                <tbody class="divide-y divide-ink/5">
                    @foreach ($order->items as $i)
                        <tr>
                            <td class="py-2.5"><b>{{ $i->name }}</b>@if ($i->option_name)<span class="text-mist"> · {{ $i->option_name }}</span>@endif <span class="chip ml-1 bg-paper">{{ $i->dine_option }}</span></td>
                            <td class="num py-2.5 text-right">{{ number_format($i->unit_price) }}</td>
                            <td class="num py-2.5 text-right">{{ $i->qty }}</td>
                            <td class="num py-2.5 text-right font-bold">{{ number_format($i->line_total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-ink/10"><td colspan="3" class="pt-3 font-bold">합계</td><td class="num pt-3 text-right text-xl font-black">{{ number_format($order->total_amount) }}<span class="font-sans text-sm">원</span></td></tr>
                </tfoot>
            </table>
        </section>

        @if ($movements->isNotEmpty())
            <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
                <h2 class="font-bold">재고 변동</h2>
                <ul class="mt-3 space-y-1 text-sm">
                    @foreach ($movements as $m)
                        <li class="flex justify-between gap-3">
                            <span>{{ $m->product->name }}@if ($m->option)<span class="text-mist"> · {{ $m->option->name }}</span>@endif <span class="chip bg-paper">{{ $m->typeLabel() }}</span></span>
                            <span class="num"><b @class(['text-signal' => $m->qty < 0, 'text-stick' => $m->qty > 0])>{{ $m->qty > 0 ? '+' : '' }}{{ $m->qty }}</b> <span class="text-mist">→ {{ $m->stock_after }}</span></span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @isset($webhooks)
            @if ($webhooks->isNotEmpty())
                <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
                    <h2 class="font-bold">매장 ERP(비트EIS) 전송</h2>
                    <ul class="mt-3 space-y-1 text-sm">
                        @foreach ($webhooks as $w)
                            <li class="flex justify-between gap-3"><span class="num text-xs text-mist">{{ \Illuminate\Support\Carbon::parse($w->created_at)->format('m.d H:i:s') }}</span><span @class(['font-semibold', 'text-stick' => $w->ok, 'text-signal' => ! $w->ok])>{{ $w->result }}</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endisset
    </div>

    <aside class="space-y-5">
        <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <h2 class="font-bold">진행</h2>
            <ol class="mt-3 space-y-2 text-sm">
                @foreach ($timeline as $label => $at)
                    <li class="flex justify-between gap-3"><span>{{ $label }}</span><span class="num text-mist">{{ $at->format('m.d H:i:s') }}</span></li>
                @endforeach
            </ol>
            @if ($order->cancel_reason)<p class="mt-3 rounded-2xl bg-signal/10 px-3 py-2 text-sm text-signal">취소 사유: {{ $order->cancel_reason }}</p>@endif
            @if ($order->cancel_requested_at)
                <div class="mt-3 rounded-2xl bg-paper px-3 py-2 text-sm">
                    <p class="font-semibold">구매자 취소 요청 <span class="num text-xs font-normal text-mist">{{ $order->cancel_requested_at->format('m.d H:i') }}</span></p>
                    <p class="text-mist">{{ $order->cancel_request_reason }}</p>
                    @if ($order->cancel_responded_at)
                        <p class="mt-1"><b>{{ $order->cancel_request_result === 'approved' ? '승인' : '거절' }}</b>@if ($order->cancel_request_response) · {{ $order->cancel_request_response }}@endif</p>
                    @else
                        <p class="mt-1 font-semibold text-signal">매장 답변 대기 중</p>
                    @endif
                </div>
            @endif
        </section>

        <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5">
            <h2 class="font-bold">결제 · 구매자</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-mist">매장</dt><dd>{{ $order->store->name }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-mist">결제 수단</dt><dd>{{ $pay }}</dd></div>
                @if ($order->pg_tid)<div class="flex justify-between gap-3"><dt class="text-mist">결제 번호</dt><dd class="num text-xs">{{ $order->pg_tid }}</dd></div>@endif
                <div class="flex justify-between gap-3"><dt class="text-mist">수수료율</dt><dd class="num">{{ rtrim(rtrim($order->commission_rate, '0'), '.') }}%</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-mist">휴대폰</dt><dd class="num">{{ $order->buyer_phone ? preg_replace('/(\d{3})(\d{3,4})(\d{4})/', '$1-****-$3', $order->buyer_phone) : '—' }}</dd></div>
                @if ($order->ticket)<div class="flex justify-between gap-3"><dt class="text-mist">좌석</dt><dd>{{ $order->ticket->seatLabel() }}</dd></div>@endif
                @if ($order->capacitySlot)<div class="flex justify-between gap-3"><dt class="text-mist">주문 시간대</dt><dd>{{ ['pre' => '공연 전', 'intermission' => '인터미션', 'post' => '공연 후'][$order->capacitySlot->slot] }}</dd></div>@endif
                <div class="flex justify-between gap-3"><dt class="text-mist">약관 동의</dt><dd class="num text-xs">{{ $order->terms_agreed_at?->format('m.d H:i:s') ?? '—' }}</dd></div>
                @if ($order->requires_id_check)<div class="flex justify-between gap-3"><dt class="text-mist">성인 확인</dt><dd class="num text-xs">{{ $order->adult_confirmed_at?->format('m.d H:i:s') ?? ($order->channel === 'pos' ? '현장 확인' : '—') }}</dd></div>@endif
                @if ($order->settlement)
                    <div class="flex justify-between gap-3"><dt class="text-mist">정산서</dt>
                        <dd><a class="row-link" href="{{ route(auth()->user()->isSuperAdmin() ? 'admin.settlements.show' : 'partner.settlements.show', $order->settlement) }}">#{{ $order->settlement->id }} {{ $order->settlement->statusLabel() }}</a></dd></div>
                @endif
            </dl>
        </section>

        @stack('order-aside')
    </aside>
</div>
