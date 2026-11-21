{{-- 쿠폰·스탬프 메뉴 안의 탭 (원본 stamp_status.php 의 탭 구성) --}}
<div class="mb-5 flex flex-wrap gap-2 text-sm">
    @foreach ([['admin.coupons.index', '쿠폰'], ['admin.coupons.stamps', '회원별 스탬프']] as [$route, $label])
        <a href="{{ route($route) }}" @class(['chip !px-4 !py-2', 'bg-ink text-white' => request()->routeIs($route), 'bg-white ring-1 ring-ink/10' => ! request()->routeIs($route)])>{{ $label }}</a>
    @endforeach
</div>
