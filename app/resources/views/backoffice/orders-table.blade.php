{{-- 주문 표 (업체 판매·취소 내역, 운영사 전체 주문 공용). $showVendor, $mode = sales|cancels|all --}}
@php($showVendor = $showVendor ?? false)
@php($mode = $mode ?? 'all')
@if ($orders->isEmpty())
    <div class="rounded-3xl bg-white px-6 py-12 text-center ring-1 ring-ink/5">
        <p class="font-bold">{{ $mode === 'cancels' ? '이 기간에 취소된 주문이 없어요' : '이 기간에 주문이 없어요' }}</p>
        <p class="mt-1 text-sm text-mist">기간을 넓혀서 다시 찾아보세요.</p>
    </div>
@else
    <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
        <table class="w-full min-w-[860px] text-sm">
            <thead class="border-b border-ink/5 text-left text-xs text-mist">
                <tr>
                    <th class="px-5 py-3 font-semibold">{{ $mode === 'cancels' ? '취소 일시' : '주문 일시' }}</th>
                    <th class="px-3 py-3 font-semibold">픽업번호</th>
                    @if ($showVendor)<th class="px-3 py-3 font-semibold">업체</th>@endif
                    <th class="px-3 py-3 font-semibold">매장</th>
                    <th class="px-3 py-3 font-semibold">주문 상품</th>
                    <th class="px-3 py-3 font-semibold">결제</th>
                    <th class="px-3 py-3 text-right font-semibold">금액</th>
                    <th class="px-5 py-3 font-semibold">{{ $mode === 'cancels' ? '취소 사유' : '상태' }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink/5">
                @foreach ($orders as $o)
                    <tr>
                        <td class="num whitespace-nowrap px-5 py-3 text-xs">{{ ($mode === 'cancels' ? $o->cancelled_at : $o->created_at)?->format('m.d H:i') }}</td>
                        <td class="num px-3 py-3 font-bold">{{ $o->pickup_code }}</td>
                        @if ($showVendor)<td class="px-3 py-3">{{ $o->store->vendor?->name ?? '—' }}</td>@endif
                        <td class="px-3 py-3">{{ $o->store->name }}</td>
                        <td class="px-3 py-3">
                            {{ $o->items->map(fn ($i) => $i->name.($i->option_name ? '('.$i->option_name.')' : '').' ×'.$i->qty)->join(', ') }}
                            @if ($o->ticket)<span class="block text-xs text-mist">좌석 {{ $o->ticket->seatLabel() }}</span>@endif
                        </td>
                        <td class="px-3 py-3 text-xs">{{ ['card' => '카드', 'pos_cash' => '현장 현금', 'pos_transfer' => '현장 계좌이체', 'pos_card' => '현장 카드'][$o->pay_method] ?? $o->pay_method }}</td>
                        <td @class(['num px-3 py-3 text-right font-bold', 'line-through text-mist' => $mode === 'cancels'])>{{ number_format($o->total_amount) }}</td>
                        <td class="px-5 py-3">
                            @if ($mode === 'cancels')
                                <span class="text-sm">{{ $o->cancel_reason ?? '—' }}</span>
                                <span class="block text-xs text-mist">{{ $o->status->label() }}</span>
                            @else
                                <span @class(['chip',
                                    'bg-pick' => $o->status->value === 4,
                                    'bg-stick/10 text-stick' => in_array($o->status->value, [2, 3]),
                                    'bg-ink/10' => in_array($o->status->value, [5, 6]),
                                    'bg-signal/10 text-signal' => in_array($o->status->value, [11, 14]),
                                ])>{{ $o->status->label() }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $orders->links() }}</div>
@endif
