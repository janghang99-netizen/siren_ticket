<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Services\DadamdaProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 다담다 이관: 원본 arena1(도봉빵) 상품 34개를 새 매장으로 */
class DadamdaProductImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_options_combine_choices_and_addons(): void
    {
        $opts = app(DadamdaProductImporter::class)->options(['ICE（+0원)', 'HOT（+0원)'], ['샷 추가 （+500원)']);
        $this->assertSame([['HOT', 0], ['HOT + 샷 추가', 500], ['ICE', 0], ['ICE + 샷 추가', 500]], $opts);

        // 고르는 옵션 없이 추가 옵션만 있으면 '기본'을 둔다
        $this->assertSame([['기본', 0], ['기본 + 진하게', 500]], app(DadamdaProductImporter::class)->options([], ['진하게 （+500원)']));
        $this->assertSame([], app(DadamdaProductImporter::class)->options([], []));
    }

    public function test_imports_arena1_products_into_new_store(): void
    {
        $this->artisan('dadamda:import-products', ['--store' => 'dobonpang'])
            ->expectsOutputToContain('새 매장을 만들었어요: 도봉빵')
            ->expectsOutputToContain('새로 34개')
            ->assertSuccessful();

        $store = Store::where('code', 'dobonpang')->firstOrFail();
        $this->assertFalse((bool) $store->is_open);   // 운영사가 확인하고 연다
        $this->assertSame(34, Product::where('store_id', $store->id)->count());
        $this->assertSame(19, Product::where('store_id', $store->id)->where('is_visible', true)->count());   // 판매중지 15개는 숨김
        $this->assertEqualsCanonicalizing(['베이커리류', '디저트류', '커피류', '에이드/티류'], ProductCategory::where('store_id', $store->id)->pluck('name')->all());

        // 실제로 세던 재고는 재고 원장에 남기고, 1만 이상(무제한으로 쓰던 값)은 재고 관리 안 함
        $ciabatta = Product::where('legacy_ref', 'dadamda:534')->firstOrFail();
        $this->assertSame('올리브 치아바타', $ciabatta->name);
        $this->assertSame(4300, $ciabatta->price);
        $this->assertSame(3, $ciabatta->stock);
        $this->assertSame('order', $ciabatta->stock_deduct_at);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $ciabatta->id, 'type' => 'adjust', 'qty' => 3]);
        $this->assertNull(Product::where('legacy_ref', 'dadamda:555')->value('stock'));   // 일회용 봉투 99991

        $americano = Product::where('legacy_ref', 'dadamda:549')->firstOrFail();
        $this->assertSame(['HOT' => 0, 'HOT + 샷 추가' => 500, 'ICE' => 0, 'ICE + 샷 추가' => 500], $americano->options()->orderBy('id')->pluck('extra_price', 'name')->all());
        $this->assertSame('We Trust - Specialty Blending Recipe', $americano->description);
    }

    public function test_running_again_updates_without_duplicates_or_stock_reset(): void
    {
        $this->artisan('dadamda:import-products', ['--store' => 'dobonpang'])->assertSuccessful();
        $ciabatta = Product::where('legacy_ref', 'dadamda:534')->firstOrFail();
        $ciabatta->update(['stock' => 1]);   // 그 사이 팔렸다

        $this->artisan('dadamda:import-products', ['--store' => 'dobonpang'])
            ->expectsOutputToContain('새로 0개 · 갱신 34개')
            ->assertSuccessful();

        $this->assertSame(34, Product::whereNotNull('legacy_ref')->count());
        $this->assertSame(1, $ciabatta->fresh()->stock);
        $this->assertSame(4, Product::where('legacy_ref', 'dadamda:549')->firstOrFail()->options()->count());
    }

    public function test_other_sellers_are_not_imported(): void
    {
        $this->artisan('dadamda:import-products', ['--seller' => 'mypetsns', '--store' => 'x'])
            ->expectsOutputToContain('mypetsns 의 상품이 파일에 없어요')
            ->assertFailed();
        $this->assertNull(Store::where('code', 'x')->first());
    }

    public function test_images_are_downloaded_only_from_dadamda(): void
    {
        Storage::fake('local');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
        Http::fake(['dadamda.store/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

        $this->artisan('dadamda:import-products', ['--store' => 'dobonpang', '--images' => true])
            ->expectsOutputToContain('사진 34장')
            ->assertSuccessful();

        $path = Product::where('legacy_ref', 'dadamda:534')->value('image_path');
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        Http::assertSent(fn ($req) => str_starts_with($req->url(), 'https://dadamda.store/wys2/'));
    }
}
