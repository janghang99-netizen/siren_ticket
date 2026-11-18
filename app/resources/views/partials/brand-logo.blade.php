{{-- 사이트 로고 (DAL LAB). 원본은 큰 투명 PNG(images/brand/dallab-logo-*.png), 화면에는 높이 160px 판을 줄여서 쓴다.
     다크모드는 흰 로고, 라이트모드는 짙은 로고. $h = 화면 높이(px) --}}
@php($h = $h ?? 40)
<img src="{{ asset('images/brand/dallab-logo-white-160.png') }}" alt="{{ $site['brand_name'] ?? 'DAL LAB' }}" height="{{ $h }}" style="height: {{ $h }}px; width: auto" class="logo-on-dark block" decoding="async">
<img src="{{ asset('images/brand/dallab-logo-ink-160.png') }}" alt="" aria-hidden="true" height="{{ $h }}" style="height: {{ $h }}px; width: auto" class="logo-on-light block" decoding="async">
