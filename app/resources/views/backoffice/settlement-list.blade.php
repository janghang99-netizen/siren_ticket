{{-- 정산서 목록 (운영사·업체 공용). $showVendor, $route = 상세 라우트 이름 --}}
@if ($settlements->isEmpty())
    <div class="rounded-3xl bg-white px-6 py-12 text-center ring-1 ring-ink/5">
        <p class="font-bold">아직 정산서가 없어요</p>
        <p class="mt-1 text-sm text-mist">{{ $showVendor ? '위에서 기간을 골라 정산서를 만들어 주세요.' : '운영사가 정산서를 만들면 여기에 보여요.' }}</p>
    </div>
@else
    <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
        <table class="w-full min-w-[720px] text-sm">
            <thead class="border-b border-ink/5 text-left text-xs text-mist">
                <tr>
                    <th class="px-5 py-3 font-semibold">정산 기간</th>
                    @if ($showVendor)<th class="px-3 py-3 font-semibold">업체</th>@endif
                    <th class="px-3 py-3 text-right font-semibold">주문</th>
                    <th class="px-3 py-3 text-right font-semibold">매출</th>
                    <th class="px-3 py-3 text-right font-semibold">수수료</th>
                    <th class="px-3 py-3 text-right font-semibold">지급액</th>
                    <th class="px-5 py-3 font-semibold">상태</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink/5">
                @foreach ($settlements as $s)
                    <tr data-href="{{ route($route, $s) }}">
                        <td class="px-5 py-3"><a href="{{ route($route, $s) }}" class="num font-semibold underline-offset-4 hover:underline">{{ $s->period_start->format('Y.m.d') }} – {{ $s->period_end->format('m.d') }}</a></td>
                        @if ($showVendor)<td class="px-3 py-3">{{ $s->vendor->name }}</td>@endif
                        <td class="num px-3 py-3 text-right">{{ number_format($s->order_count) }}</td>
                        @php $card = ($cards ?? [])[$s->id] ?? null; @endphp
                        @if ($card)
                            {{-- 원샷 정산 완료목록(운영사): 페이레터를 불러온 업체는 카드결제 기준으로 표시만 바꾼다 (원본 jungsan_list.php) --}}
                            <td class="num px-3 py-3 text-right">{{ number_format($card['card']) }}<p class="text-xs text-mist">주문 기준 {{ number_format($s->gross_amount) }}</p></td>
                            <td class="num px-3 py-3 text-right text-mist">−{{ number_format($card['fee'] + $card['vat']) }}</td>
                            <td class="num px-3 py-3 text-right align-top font-black">{{ number_format($card['pay']) }}
                                @include('admin.partials.card-note', ['c' => $card, 'vendor' => $s->vendor, 'old' => $s->payout_amount, 'src' => 'done'])</td>
                        @else
                            <td class="num px-3 py-3 text-right">{{ number_format($s->gross_amount) }}</td>
                            <td class="num px-3 py-3 text-right text-mist">−{{ number_format($s->commission_amount) }}</td>
                            <td class="num px-3 py-3 text-right font-black">{{ number_format($s->payout_amount) }}</td>
                        @endif
                        <td class="px-5 py-3">
                            <span @class(['chip', 'bg-ink/10' => $s->status === 'paid', 'bg-pick' => $s->status !== 'paid'])>{{ $s->statusLabel() }}</span>
                            @if ($s->overdueDays())<span class="chip bg-signal/10 text-signal">지연 {{ $s->overdueDays() }}일</span>@endif
                            @if ($s->disputed_at && ! $s->dispute_replied_at)<span class="chip bg-signal text-white">이의제기</span>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $settlements->links() }}</div>
@endif
