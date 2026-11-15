@extends('layouts.backoffice')
@section('title', '혜택 원장')
@section('eyebrow', '해시 체인 · 블록체인 앵커')

@section('actions')
    <form method="POST" action="{{ route('admin.ledger.anchor') }}">@csrf<button class="btn-ink !py-2.5 text-sm">지금 앵커 만들기</button></form>
@endsection

@section('content')
    <div class="mb-5 grid gap-3 sm:grid-cols-3">
        <div @class(['rounded-3xl p-5', 'bg-ink text-paper' => $check['ok'], 'bg-signal text-white' => ! $check['ok']])>
            <p class="text-xs opacity-70">체인 검증</p>
            <p class="mt-1 text-2xl font-black">{{ $check['ok'] ? '이상 없음' : '위변조 발견' }}</p>
            <p class="text-xs opacity-70">{{ $check['ok'] ? number_format($check['checked']).'건 처음부터 다시 계산' : '#'.$check['broken_at'].'번 기록부터 체인이 끊겼어요' }}</p>
        </div>
        <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/5"><p class="text-xs text-mist">아직 앵커 안 된 기록</p><p class="num mt-1 text-2xl font-black">{{ number_format($pending) }}<span class="font-sans text-sm">건</span></p><p class="text-xs text-mist">매일 03:00 자동으로 묶어요</p></div>
        <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/5"><p class="text-xs text-mist">앵커 대상</p><p class="mt-1 font-black">{{ class_basename($network) === 'LocalAnchor' ? '로컬 (체인 미정)' : class_basename($network) }}</p><p class="text-xs text-mist">체인이 정해지면 BlockchainAnchor 구현만 바꿔요</p></div>
    </div>

    <section class="mb-6 rounded-3xl bg-white p-6 text-sm leading-relaxed ring-1 ring-ink/5">
        <h2 class="font-bold">어떻게 지키나요</h2>
        <ol class="mt-2 list-decimal space-y-1 pl-5 text-ink/80">
            <li>마일리지·쿠폰·스탬프의 모든 적립·사용·회수는 원장에 <b>추가만</b> 돼요. 고치거나 지우지 않아요.</li>
            <li>각 기록은 바로 앞 기록의 해시를 품고 있어서, 한 줄만 고쳐도 그 뒤 체인이 모두 맞지 않게 돼요. 이 화면을 열 때마다 처음부터 다시 계산해요.</li>
            <li>매일 아직 묶이지 않은 기록의 <b>머클 루트</b>(해시 하나)를 만들어요. 블록체인에 이 값만 올리면, 개인정보 없이 "이 날 원장이 이 상태였다"를 외부에서 증명할 수 있어요.</li>
            <li>원장에는 휴대폰 번호 대신 고객 키만 들어가요.</li>
        </ol>
    </section>

    <h2 class="mb-3 text-lg font-bold">앵커</h2>
    <div class="mb-6 overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
        <table class="w-full min-w-[760px] text-sm">
            <thead class="border-b border-ink/5 text-left text-xs text-mist"><tr><th class="px-5 py-3 font-semibold">#</th><th class="px-3 py-3 font-semibold">때</th><th class="px-3 py-3 font-semibold">기록 범위</th><th class="px-3 py-3 font-semibold">머클 루트</th><th class="px-5 py-3 font-semibold">네트워크 · 트랜잭션</th></tr></thead>
            <tbody class="divide-y divide-ink/5">
                @forelse ($anchors as $a)
                    <tr>
                        <td class="num px-5 py-3">{{ $a->id }}</td>
                        <td class="num px-3 py-3 text-xs">{{ $a->anchored_at->format('Y.m.d H:i') }}</td>
                        <td class="num px-3 py-3 text-xs">#{{ $a->from_id }}–#{{ $a->to_id }} ({{ number_format($a->row_count) }}건)</td>
                        <td class="num px-3 py-3 text-[11px]" title="{{ $a->merkle_root }}">{{ substr($a->merkle_root, 0, 20) }}…</td>
                        <td class="num px-5 py-3 text-xs">{{ $a->network }}{{ $a->tx_hash ? ' · '.$a->tx_hash : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-8 text-center text-mist">아직 앵커가 없어요.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2 class="mb-3 text-lg font-bold">최근 기록</h2>
    <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
        <table class="w-full min-w-[860px] text-sm">
            <thead class="border-b border-ink/5 text-left text-xs text-mist"><tr><th class="px-5 py-3 font-semibold">#</th><th class="px-3 py-3 font-semibold">고객</th><th class="px-3 py-3 font-semibold">종류</th><th class="px-3 py-3 text-right font-semibold">변동</th><th class="px-3 py-3 font-semibold">대상</th><th class="px-3 py-3 font-semibold">이전 해시 → 해시</th><th class="px-5 py-3 font-semibold">앵커</th></tr></thead>
            <tbody class="divide-y divide-ink/5">
                @foreach ($rows as $r)
                    <tr data-href="{{ route('admin.customers.show', $r->customer) }}">
                        <td class="num px-5 py-3">{{ $r->id }}</td>
                        <td class="num px-3 py-3">{{ $r->customer->maskedPhone() }}</td>
                        <td class="px-3 py-3">{{ \App\Models\BenefitLedger::KINDS[$r->kind] }} {{ $r->typeLabel() }}</td>
                        <td @class(['num px-3 py-3 text-right font-bold', 'text-signal' => $r->amount < 0])>{{ $r->amount > 0 ? '+' : '' }}{{ number_format($r->amount) }}</td>
                        <td class="num px-3 py-3 text-xs">{{ $r->subject ?? '—' }}</td>
                        <td class="num px-3 py-3 text-[11px] text-mist">{{ $r->prev_hash ? substr($r->prev_hash, 0, 8) : '시작' }} → {{ substr($r->hash, 0, 8) }}</td>
                        <td class="num px-5 py-3 text-xs">{{ $r->anchor_id ? '#'.$r->anchor_id : '대기' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
