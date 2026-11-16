{{-- SNS 로그인 버튼 (키가 설정된 SNS 만) --}}
@php($providers = \App\Services\Auth\SocialAuth::enabled())
@if ($providers)
    <div class="mt-6">
        <p class="text-center text-xs font-semibold text-mist">{{ $title ?? 'SNS 계정으로 시작하기' }}</p>
        <div class="mt-2 grid gap-2">
            @foreach ($providers as $p => $label)
                <a href="{{ route('member.oauth', $p) }}" @class([
                    'flex items-center justify-center gap-2 rounded-2xl px-4 py-3 text-sm font-bold ring-1',
                    'bg-[#03C75A] text-white ring-[#03C75A]' => $p === 'naver',
                    'bg-[#FEE500] text-[#191919] ring-[#FEE500]' => $p === 'kakao',
                    'bg-white text-ink ring-ink/15' => $p === 'google',
                ])>{{ $label }}로 계속하기</a>
            @endforeach
        </div>
    </div>
@endif
