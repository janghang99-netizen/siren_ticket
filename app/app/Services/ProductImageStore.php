<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 상품 이미지 저장 — 업체가 올린 원본(최대 5MB)을 긴 변 1000px 의 WEBP 로 줄여 저장한다.
 * 팬 화면은 휴대폰 데이터로 보므로 가볍게. GD(webp) 가 없는 환경에서는 원본을 그대로 저장한다.
 */
class ProductImageStore
{
    public const MAX_SIDE = 1000;

    public const QUALITY = 82;

    /** @return string storage/app/private 기준 경로 (products/xxxx.webp) */
    public function store(UploadedFile $file): string
    {
        $name = Str::random(40);
        $resized = $this->resize($file);
        if ($resized !== null) {
            Storage::disk('local')->put("products/{$name}.webp", $resized);

            return "products/{$name}.webp";
        }

        return $file->storeAs('products', $name.'.'.$file->extension(), 'local');
    }

    private function resize(UploadedFile $file): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            return null;
        }
        $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, self::MAX_SIDE / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);   // PNG 투명 배경 유지
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagewebp($dst, null, self::QUALITY);
        $out = ob_get_clean();

        return $out !== false && $out !== '' ? $out : null;
    }
}
