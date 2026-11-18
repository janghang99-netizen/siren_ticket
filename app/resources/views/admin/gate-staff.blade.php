@extends('layouts.backoffice')
@section('title', '검표원')
@section('eyebrow', '입장 검표 · 게이트 계정')

@section('actions')
    <a href="{{ route('gate.scan') }}" target="_blank" class="btn-ghost !py-2.5 text-sm">휴대폰 검표 화면 열기</a>
@endsection

@section('content')
<div class="grid gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
    <section>
        <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
            <table class="w-full text-sm">
                <thead class="border-b border-ink/5 text-left text-xs text-mist">
                    <tr><th class="px-5 py-3 font-semibold">이름</th><th class="px-3 py-3 font-semibold">로그인 이메일</th><th class="px-3 py-3 font-semibold">기본 게이트</th><th class="px-3 py-3 text-right font-semibold">오늘 입장</th><th class="px-5 py-3"></th></tr>
                </thead>
                <tbody class="divide-y divide-ink/5">
                    @forelse ($staff as $s)
                        <tr x-data="{ edit: false }">
                            <td class="px-5 py-3 font-semibold">{{ $s->name }}</td>
                            <td class="px-3 py-3 text-mist">{{ $s->email }}</td>
                            <td class="px-3 py-3">
                                <span x-show="!edit">{{ $s->gate_name ?: '—' }}</span>
                                <form x-show="edit" x-cloak method="POST" action="{{ route('admin.gate-staff.update', $s) }}" class="flex flex-wrap gap-1.5">
                                    @csrf @method('PUT')
                                    <input name="gate_name" value="{{ $s->gate_name }}" maxlength="40" placeholder="게이트" class="field !h-9 !w-28 !py-1 text-sm" aria-label="기본 게이트">
                                    <input name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" placeholder="새 비밀번호 (바꿀 때만)" class="field !h-9 !w-44 !py-1 text-sm" aria-label="새 비밀번호">
                                    <button class="btn-ink !py-1.5 text-xs">저장</button>
                                </form>
                            </td>
                            <td class="num px-3 py-3 text-right font-bold">{{ number_format($today[$s->id] ?? 0) }}</td>
                            <td class="whitespace-nowrap px-5 py-3 text-right text-xs">
                                <button type="button" @click="edit = !edit" class="font-semibold text-mist hover:text-ink" x-text="edit ? '닫기' : '수정'"></button>
                                <form method="POST" action="{{ route('admin.gate-staff.destroy', $s) }}" class="ml-2 inline" onsubmit="return confirm('{{ $s->name }} 검표원 계정을 지울까요? 입장 기록은 남아요.')">
                                    @csrf @method('DELETE')
                                    <button class="font-semibold text-mist hover:text-signal">삭제</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-10 text-center text-mist">아직 검표원 계정이 없어요. 오른쪽에서 만들어 주세요.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-5 rounded-3xl bg-white p-6 text-sm leading-relaxed ring-1 ring-ink/5">
            <h2 class="font-bold">공연 날 검표는 이렇게</h2>
            <ol class="mt-2 list-decimal space-y-1 pl-5 text-mist">
                <li>입구 줄마다 검표원 한 명 · 휴대폰 한 대. 1만 명을 90분에 받으려면 <b class="text-ink">12~14줄</b>이 필요해요 (줄 하나 분당 8~10명).</li>
                <li>검표원 휴대폰으로 로그인하면 바로 검표 화면이 열려요. 공연을 고르고 <b class="text-ink">카메라로 검표 시작</b>.</li>
                <li>관객은 내 티켓 → <b class="text-ink">입장 QR 보기</b>. 화면이 초록이면 입장, 노랑은 이미 입장, 빨강은 거절이에요.</li>
                <li>블루투스·USB 스캐너도 검표 화면 입력칸에 연결해 쓸 수 있어요.</li>
                <li>입장은 바로 확정되고, 블록체인 ‘사용됨’ 기록은 서버가 뒤에서 차례대로 보내요.</li>
            </ol>
            <p class="mt-3 text-xs text-mist">휴대폰 카메라는 https 주소에서만 켜져요.</p>
        </div>
    </section>

    <section class="h-fit rounded-3xl bg-white p-6 ring-1 ring-ink/5">
        <h2 class="font-bold">검표원 계정 만들기</h2>
        <p class="mt-1 text-sm text-mist">검표원 계정은 휴대폰 검표 화면만 쓸 수 있어요 (매출·고객 정보는 볼 수 없어요).</p>
        <form method="POST" action="{{ route('admin.gate-staff.store') }}" class="mt-4 space-y-3">
            @csrf
            <label class="block text-sm font-semibold">이름
                <input name="name" value="{{ old('name') }}" required maxlength="40" class="field mt-1" placeholder="예: 김검표">
            </label>
            <label class="block text-sm font-semibold">로그인 이메일
                <input name="email" type="email" value="{{ old('email') }}" required maxlength="120" class="field mt-1" autocomplete="off" placeholder="gate1@example.com">
            </label>
            <label class="block text-sm font-semibold">기본 게이트 <span class="font-normal text-mist">(선택 · 휴대폰에서 바꿀 수 있어요)</span>
                <input name="gate_name" value="{{ old('gate_name') }}" maxlength="40" class="field mt-1" placeholder="예: 1번 게이트">
            </label>
            <label class="block text-sm font-semibold">비밀번호 <span class="font-normal text-mist">(8자 이상)</span>
                <input name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password" class="field mt-1">
            </label>
            @if ($errors->any())
                <p class="rounded-xl bg-signal/10 px-3 py-2 text-sm font-semibold text-signal">{{ $errors->first() }}</p>
            @endif
            <button class="btn-ink w-full">계정 만들기</button>
        </form>
    </section>
</div>
@endsection
