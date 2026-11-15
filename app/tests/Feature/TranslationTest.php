<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\Store;
use App\Models\TranslationFix;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Translation\ProductNameTranslator;
use App\Services\Translation\ProductTranslationService;
use App\Services\Translation\TranslationFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 번역 — 원본 다담다 방식 (구글 위젯 + 상품명 Claude 사전 + 오역 교정) */
class TranslationTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    /** Claude 대신 쓰는 가짜 번역기 — 실제 API 를 부르지 않는다 */
    private object $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $vendor = Vendor::create(['name' => '아레나푸드', 'state' => 'approved']);
        $this->store = Store::create(['vendor_id' => $vendor->id, 'code' => 'arena1', 'name' => '커피', 'commission_rate' => 10]);

        $this->fake = new class implements ProductNameTranslator
        {
            public array $calls = [];

            public bool $fail = false;

            public bool $ready = true;

            public function ready(): bool
            {
                return $this->ready;
            }

            public function translate(array $names, string $lang): array
            {
                if ($this->fail) {
                    throw new TranslationFailed('거절');
                }
                $this->calls[] = $names;

                return array_map(fn ($n) => strtoupper($lang).':'.$n, $names);
            }
        };
        $this->app->instance(ProductNameTranslator::class, $this->fake);
    }

    private function product(string $name): Product
    {
        return Product::create(['store_id' => $this->store->id, 'name' => $name, 'price' => 5000, 'stock' => 10]);
    }

    public function test_pending_products_are_translated_in_batches_and_renamed_products_return_to_pending(): void
    {
        foreach (range(1, 30) as $i) {
            $this->product("상품{$i}");
        }
        $gassosa = $this->product('가소사');
        $svc = app(ProductTranslationService::class);

        $this->assertSame(31, $svc->status()['en']['pending']);
        $r = $svc->run('en');
        $this->assertSame(31, $r['translated']);
        $this->assertCount(2, $this->fake->calls);                 // 25개씩 묶어서
        $this->assertCount(25, $this->fake->calls[0]);
        $this->assertSame(0, $svc->status()['en']['pending']);
        $this->assertSame(31, $svc->status()['ja']['pending']);    // 언어별로 따로

        $this->assertSame('EN:가소사', $svc->dict('en')['products']['가소사']);

        // 상품명을 바꾸면 옛 번역은 사전에서 바로 빠지고 다시 대기
        $gassosa->update(['name' => '가소사 (ICE)']);
        $svc->forget('en');
        $this->assertArrayNotHasKey('가소사', $svc->dict('en')['products']);
        $this->assertArrayNotHasKey('가소사 (ICE)', $svc->dict('en')['products']);
        $this->assertSame(1, $svc->status()['en']['pending']);
        $svc->run('en');
        $this->assertSame('EN:가소사 (ICE)', $svc->dict('en')['products']['가소사 (ICE)']);
        $this->assertSame(1, ProductTranslation::where('product_id', $gassosa->id)->count());
    }

    public function test_one_run_is_capped_and_failures_do_not_save(): void
    {
        foreach (range(1, 160) as $i) {
            $this->product("메뉴{$i}");
        }
        $svc = app(ProductTranslationService::class);
        $this->assertSame(150, $svc->run('ja')['translated']);    // 한 번에 최대 150개
        $this->assertSame(10, $svc->status()['ja']['pending']);

        $this->fake->fail = true;
        $r = $svc->run('ja');
        $this->assertSame(0, $r['translated']);
        $this->assertSame(['거절'], $r['errors']);
        $this->assertSame(10, $svc->status()['ja']['pending']);
    }

    public function test_public_dict_has_products_and_fixes(): void
    {
        $this->product('떡볶이');
        app(ProductTranslationService::class)->run('zh');

        $this->getJson(route('translate.dict', 'zh'))->assertOk()
            ->assertJsonPath('products.떡볶이', 'ZH:떡볶이')
            ->assertJsonPath('fixes', []);
        // 원본 다담다 기본 교정
        $this->getJson(route('translate.dict', 'en'))->assertOk()
            ->assertJsonPath('fixes.0', ['Organic Gasoline', 'Organic Gassosa']);
        $this->get('/translate/dict/fr')->assertNotFound();
    }

    public function test_admin_screen_runs_translation_and_manages_fixes(): void
    {
        $this->product('아메리카노 (HOT)');
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'web')->get(route('admin.translations'))->assertOk()->assertSee('설정됨')->assertSee('대기 1개');

        $this->post(route('admin.translations.test'), ['lang' => 'en'])->assertSessionHas('sample');
        $this->assertSame(0, ProductTranslation::count());          // 연결 테스트는 저장하지 않는다

        $this->post(route('admin.translations.run'), ['lang' => 'en'])->assertSessionHas('status');
        $this->assertSame('EN:아메리카노 (HOT)', ProductTranslation::first()->trans_name);

        $this->post(route('admin.translations.fixes.store'), ['lang' => 'ja', 'wrong' => 'ガソリン', 'right' => 'ガッソーサ'])->assertSessionHas('status');
        $this->getJson(route('translate.dict', 'ja'))->assertJsonPath('fixes.0', ['ガソリン', 'ガッソーサ']);   // 캐시도 바로 갱신
        $fix = TranslationFix::where('lang', 'ja')->first();
        $this->delete(route('admin.translations.fixes.destroy', $fix))->assertSessionHas('status');
        $this->getJson(route('translate.dict', 'ja'))->assertJsonPath('fixes', []);
        $this->assertDatabaseHas('audit_logs', ['action' => 'translation.fix_saved']);

        // 키가 없으면 일괄 번역을 막는다
        $this->fake->ready = false;
        $this->get(route('admin.translations'))->assertSee('ANTHROPIC_API_KEY 환경 변수');
        $this->post(route('admin.translations.run'), ['lang' => 'en'])->assertSessionHasErrors('translate');
    }

    public function test_translation_admin_is_super_admin_only(): void
    {
        $this->get(route('admin.translations'))->assertRedirect(route('login'));
        $vendorUser = User::factory()->create(['role' => 'vendor']);
        $this->actingAs($vendorUser, 'web')->get(route('admin.translations'))->assertForbidden();
    }
}
