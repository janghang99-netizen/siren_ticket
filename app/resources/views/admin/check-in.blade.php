@extends('layouts.backoffice')
@section('title', '입장 검표')
@section('eyebrow', 'NFT 티켓 · 30초 입장 코드')

@section('content')
<div class="grid gap-5 xl:grid-cols-[1fr_1fr]">
    <section class="rounded-3xl bg-white p-6 ring-1 ring-ink/5"
             x-data="{ code: '', result: null, ok: false, busy: false,
                 async scan() {
                     if (!this.code) return; this.busy = true;
                     try { const r = await api('{{ route('admin.check-in.scan') }}', 'POST', { code: this.code }); this.ok = true; this.result = r.message }
                     catch (e) { this.ok = false; this.result = e.message }
                     this.code = ''; this.busy = false; this.$refs.input.focus();
                 } }">
        <h2 class="font-bold">코드 스캔</h2>
        <p class="mt-1 text-sm text-mist">팬 화면의 입장 코드를 스캐너로 읽거나 입력하세요. 코드는 30초마다 바뀌어서 캡처 화면은 통과하지 못하고, 한 번 입장한 티켓은 다시 쓸 수 없어요.</p>
        <form @submit.prevent="scan()" class="mt-4 flex gap-2">
            <label for="code" class="sr-only">입장 코드</label>
            <input id="code" x-ref="input" x-model="code" class="field num" placeholder="SIREN1.A1-XXXXXXXX.…" autofocus autocomplete="off">
            <button class="btn-ink shrink-0" :disabled="busy">확인</button>
        </form>
        <p x-show="result" x-cloak role="status" class="mt-4 rounded-2xl px-5 py-4 text-lg font-black"
           :class="ok ? 'bg-ink text-white' : 'bg-signal text-white'" x-text="result"></p>
        @error('code')<p class="mt-4 rounded-2xl bg-signal px-5 py-4 text-lg font-black text-white">{{ $message }}</p>@enderror
    </section>

    <section>
        <h2 class="mb-3 text-lg font-bold">최근 입장</h2>
        <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
            <table class="w-full text-sm">
                <thead class="border-b border-ink/5 text-left text-xs text-mist"><tr><th class="px-5 py-3 font-semibold">시각</th><th class="px-3 py-3 font-semibold">공연</th><th class="px-3 py-3 font-semibold">좌석</th><th class="px-5 py-3 font-semibold">NFT</th></tr></thead>
                <tbody class="divide-y divide-ink/5">
                    @forelse ($recent as $t)
                        <tr data-href="{{ route('admin.tickets.show', $t) }}">
                            <td class="num px-5 py-3">{{ $t->checked_in_at?->format('H:i:s') }}</td>
                            <td class="px-3 py-3">{{ $t->event->artist }}</td>
                            <td class="px-3 py-3">{{ $t->seatLabel() }}</td>
                            <td class="num px-5 py-3 text-xs">{{ $t->token ? '#'.$t->token->token_id : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-8 text-center text-mist">아직 입장한 사람이 없어요.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
