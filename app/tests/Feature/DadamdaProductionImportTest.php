<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 다담다 이관: arena1 생산 기록 — 원본 판매자 생산 화면의 최근 30건 (2026-11-10 ~ 11-16) */
class DadamdaProductionImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('dadamda:import-products', ['--store' => 'dobonpang'])->assertSuccessful();
    }

    public function test_imports_history_without_touching_stock(): void
    {
        $stockBefore = Product::pluck('stock', 'id')->all();
        $movementsBefore = DB::table('stock_movements')->count();

        $this->artisan('dadamda:import-production', ['--store' => 'dobonpang'])
            ->expectsOutputToContain('생산 기록 30건 · 합계 134개 · 이미 있음 0건')
            ->assertSuccessful();

        $this->assertSame(30, DB::table('product_productions')->count());
        $this->assertSame(0, DB::table('product_productions')->where('webhook_sent', false)->count());   // EIS 로 다시 보내지 않는다
        $this->assertSame($stockBefore, Product::pluck('stock', 'id')->all());
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());

        // 같은 이름이면 판매 중인 상품 (버터가득 소금빵: 542 판매중 / 472 판매중지)
        $butter = DB::table('product_productions')->where('legacy_ref', 'dadamda:645')->first();
        $this->assertSame(Product::where('legacy_ref', 'dadamda:542')->value('id'), $butter->product_id);
        $this->assertSame('2026-11-16', substr((string) $butter->production_date, 0, 10));
    }

    public function test_running_again_does_not_duplicate(): void
    {
        $this->artisan('dadamda:import-production', ['--store' => 'dobonpang'])->assertSuccessful();
        $this->artisan('dadamda:import-production', ['--store' => 'dobonpang'])
            ->expectsOutputToContain('생산 기록 0건 · 합계 0개 · 이미 있음 30건')
            ->assertSuccessful();
        $this->assertSame(30, DB::table('product_productions')->count());
    }
}
