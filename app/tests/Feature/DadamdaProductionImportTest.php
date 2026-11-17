<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 다담다 이관: arena1 생산 기록 전체 — 원본 DB product_production 표 653건 (2026-06-25 ~ 11-17) */
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
            ->expectsOutputToContain('생산 기록 653건 · 합계 2964개 · 이미 있음 0건')
            ->assertSuccessful();

        $this->assertSame(653, DB::table('product_productions')->count());
        // 원본이 EIS 로 보낸 기록은 보냄으로, 원본에서 못 보낸 18건은 못 보냄 그대로 (자동으로 다시 보내지 않는다)
        $this->assertSame(18, DB::table('product_productions')->where('webhook_sent', false)->count());
        $this->assertSame($stockBefore, Product::pluck('stock', 'id')->all());
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());

        $butter = DB::table('product_productions')->where('legacy_ref', 'dadamda:645')->first();
        $this->assertSame(Product::where('legacy_ref', 'dadamda:542')->value('id'), $butter->product_id);
        $this->assertSame('2026-11-16', substr((string) $butter->production_date, 0, 10));

        // 이름이 바뀐 상품도 원본 상품 번호로 연결 (4번 기록 '초록대파 소금빵' → 지금 이름 '진도대파 소금빵', 541)
        $this->assertSame(Product::where('legacy_ref', 'dadamda:541')->value('id'),
            DB::table('product_productions')->where('legacy_ref', 'dadamda:4')->value('product_id'));
        // 원본 등록 시각 그대로
        $this->assertSame('2026-11-17 11:56:35',
            substr((string) DB::table('product_productions')->where('legacy_ref', 'dadamda:653')->value('created_at'), 0, 19));
    }

    public function test_running_again_does_not_duplicate(): void
    {
        $this->artisan('dadamda:import-production', ['--store' => 'dobonpang'])->assertSuccessful();
        $this->artisan('dadamda:import-production', ['--store' => 'dobonpang'])
            ->expectsOutputToContain('생산 기록 0건 · 합계 0개 · 이미 있음 653건')
            ->assertSuccessful();
        $this->assertSame(653, DB::table('product_productions')->count());
    }
}
