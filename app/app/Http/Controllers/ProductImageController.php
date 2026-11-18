<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 상품 이미지 제공 — storage/app/products 의 파일을 그대로 내보낸다.
 * 파일 이름은 업로드할 때 무작위로 만들어져서(40자) 추측할 수 없고, 바꾸면 이름도 바뀌므로 오래 캐시해도 된다.
 */
class ProductImageController extends Controller
{
    public function show(string $file): BinaryFileResponse
    {
        return $this->serve('products/'.$file);
    }

    /** 작품 포스터·배너 */
    public function production(string $file): BinaryFileResponse
    {
        return $this->serve('productions/'.$file);
    }

    private function serve(string $path): BinaryFileResponse
    {
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
