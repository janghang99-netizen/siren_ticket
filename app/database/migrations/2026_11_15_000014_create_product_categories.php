<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 매장별 상품 카테고리 — 이름·순서·숨기기를 매장이 관리한다.
 * 상품마다 글자로 적던 products.category 는 카테고리로 옮기고 지운다 (오타로 갈라지는 문제 방지).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->string('name', 30);
            $t->unsignedInteger('sort')->default(0);
            $t->boolean('is_visible')->default(true);   // 숨기면 그 카테고리 상품은 팬 메뉴·현장판매에서 빠진다
            $t->timestamps();
            $t->unique(['store_id', 'name']);
            $t->index(['store_id', 'sort']);
        });

        Schema::table('products', function (Blueprint $t) {
            $t->foreignId('category_id')->nullable()->after('store_id')->constrained('product_categories')->nullOnDelete();
        });

        // 기존 글자 카테고리 → 카테고리 (매장마다, 처음 쓰인 상품 순서대로)
        $rows = DB::table('products')->whereNotNull('category')->where('category', '!=', '')
            ->orderBy('store_id')->orderBy('sort')->orderBy('id')->get(['id', 'store_id', 'category']);
        $ids = [];
        $sort = [];
        foreach ($rows as $r) {
            $name = trim($r->category);
            $key = $r->store_id.'|'.$name;
            if (! isset($ids[$key])) {
                $sort[$r->store_id] = ($sort[$r->store_id] ?? 0) + 1;
                $ids[$key] = DB::table('product_categories')->insertGetId([
                    'store_id' => $r->store_id, 'name' => $name, 'sort' => $sort[$r->store_id], 'is_visible' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('products')->where('id', $r->id)->update(['category_id' => $ids[$key]]);
        }

        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->string('category', 30)->nullable()->after('name');
        });
        foreach (DB::table('product_categories')->get() as $c) {
            DB::table('products')->where('category_id', $c->id)->update(['category' => $c->name]);
        }
        Schema::table('products', function (Blueprint $t) {
            $t->dropConstrainedForeignId('category_id');
        });
        Schema::dropIfExists('product_categories');
    }
};
