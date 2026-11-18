@extends('layouts.ticket')
@section('title', '블록체인 티켓')
@section('genre', '')

@section('content')
    @include('partials.blockchain-band')

    {{-- 티켓 한 장의 일생: 예매 → 발행 → 입장 → 사용됨 / 취소 → 소각 --}}
    <section class="tix-wrap mt-16" aria-labelledby="life-title">
        <h2 id="life-title" class="tix-h2">티켓 한 장이 블록체인에 남는 과정</h2>
        <p class="mt-2 text-[15px] text-mist">관객은 지갑이나 코인을 몰라도 돼요. 예매만 하면 나머지는 다담다가 처리해요.</p>
        <ol class="mt-8 grid gap-4 md:grid-cols-4">
            @foreach ([
                ['01', '예매', '회원으로 좌석을 고르고 결제해요. 계정에 블록체인 지갑이 자동으로 만들어져요.'],
                ['02', '발행', '좌석 하나에 토큰 하나가 카이아 블록체인에 발행돼요. 잠긴 토큰이라 다른 지갑으로 옮길 수 없어요.'],
                ['03', '입장', '내 티켓의 입장 코드는 30초마다 바뀌는 서명 값이에요. 캡처한 화면으로는 들어갈 수 없어요.'],
                ['04', '사용됨', '검표하면 그 토큰에 ‘사용됨’이 블록체인에 기록돼요. 같은 티켓으로 두 번 들어갈 수 없어요.'],
            ] as [$no, $t, $d])
                <li class="relative rounded-3xl bg-paper p-6">
                    <span class="font-display text-[44px] font-black leading-none text-ink/10">{{ $no }}</span>
                    <p class="mt-3 text-lg font-black">{{ $t }}</p>
                    <p class="mt-2 text-[14px] leading-relaxed text-ink/70">{{ $d }}</p>
                </li>
            @endforeach
        </ol>
        <p class="mt-4 rounded-2xl bg-paper px-5 py-4 text-[14px] text-ink/75">예매를 취소하면 그 토큰은 블록체인에서 <b>소각</b>돼요. 취소한 티켓이 다시 쓰이거나 떠돌 일이 없어요.</p>
    </section>

    {{-- 자주 묻는 질문 --}}
    <section class="tix-wrap mt-16" aria-labelledby="faq-title">
        <h2 id="faq-title" class="tix-h2">자주 묻는 질문</h2>
        <div class="mt-6 divide-y divide-ink/[.08] rounded-3xl ring-1 ring-ink/[.08]">
            @foreach ([
                ['코인이나 지갑 앱이 있어야 하나요?', '아니요. 회원으로 예매하면 계정에 지갑이 자동으로 만들어지고, 블록체인 수수료(가스비)도 다담다가 내요. 관객은 평소처럼 예매하고 입장하면 돼요.'],
                ['블록체인에 제 이름이나 전화번호가 올라가나요?', '아니요. 블록체인은 한 번 올라가면 지울 수 없어서, 공연 이름·일시·좌석만 올려요. 개인정보는 다담다 서버에만 보관하고 법에 따라 관리해요.'],
                ['친구에게 티켓을 넘겨줄 수 있나요?', '블록체인 토큰은 잠겨 있어 다른 지갑으로 옮길 수 없어요. 암표 거래를 막기 위해서예요. 못 가게 되면 취소 규정에 따라 예매를 취소해 주세요.'],
                ['내 티켓이 블록체인에 있는지 어떻게 확인하나요?', '‘내 티켓’에서 티켓마다 토큰 번호와 지갑 주소, 블록체인 기록 보기 링크를 볼 수 있어요. 누구나 블록체인 탐색기에서 같은 기록을 확인할 수 있어요.'],
                ['모든 나라에서 블록체인으로 발행되나요?', '네. 사는 나라와 상관없이 회원으로 예매한 티켓은 똑같이 블록체인에 발행돼요.'],
            ] as [$q, $a])
                <details class="group px-6 py-5">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-[16px] font-bold">
                        {{ $q }}
                        <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 text-mist transition group-open:rotate-180" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>
                    </summary>
                    <p class="mt-3 text-[15px] leading-relaxed text-ink/75">{{ $a }}</p>
                </details>
            @endforeach
        </div>
        <div class="mt-8 flex flex-wrap gap-2">
            <a href="{{ route('home') }}" class="inline-flex h-12 items-center rounded-full bg-ink px-6 text-[15px] font-bold text-white hover:opacity-90">공연 보러 가기</a>
            <a href="{{ route('tickets') }}" class="inline-flex h-12 items-center rounded-full bg-paper px-6 text-[15px] font-bold hover:bg-paper-deep">내 티켓에서 확인하기</a>
        </div>
    </section>
@endsection
