<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 데모 상품 사진 — database/seeders/demo-products/*.webp (직접 그린 일러스트, 800px)
 * 업체가 올린 사진과 똑같이 local 디스크 products/ 에 무작위 이름으로 복사해 둔다.
 *
 * 이미 만든 데모 DB 에 넣을 때: php artisan tinker --execute="Database\Seeders\DemoProductImages::fillMissing()"
 */
class DemoProductImages
{
    public const FILES = [
        '아메리카노' => 'americano', '카페라떼' => 'latte', '레몬에이드' => 'lemonade',
        '소금빵' => 'salt-bread', '크루아상' => 'croissant', '인터미션 세트' => 'set',
        '생수' => 'water', '이온음료' => 'ion-drink', '탄산음료' => 'soda',
        '생맥주 500' => 'draft-beer', '캔맥주' => 'can-beer', '무알콜 맥주' => 'non-alcohol',
        '클래식 핫도그' => 'hotdog', '치즈 핫도그' => 'cheese-hotdog', '감자튀김' => 'fries',
        '응원봉 배터리' => 'battery', '포토카드 랜덤팩' => 'photocard', '슬로건 타월' => 'slogan-towel',
    ];

    /** 이름이 맞는 데모 사진이 있으면 붙인다. 이미 사진이 있으면 그대로 둔다. */
    public static function attach(Product $product): bool
    {
        $file = self::FILES[$product->name] ?? null;
        $src = $file ? __DIR__.'/demo-products/'.$file.'.webp' : null;
        if (! $src || ! is_file($src) || $product->image_path) {
            return false;
        }
        $path = 'products/'.Str::random(40).'.webp';
        Storage::disk('local')->put($path, file_get_contents($src));
        $product->forceFill(['image_path' => $path])->save();

        return true;
    }

    /** 사진 없는 데모 상품 모두에 붙이고, 붙인 개수를 돌려준다. */
    public static function fillMissing(): int
    {
        return Product::whereNull('image_path')->whereIn('name', array_keys(self::FILES))->get()
            ->filter(fn (Product $p) => self::attach($p))->count();
    }
}
