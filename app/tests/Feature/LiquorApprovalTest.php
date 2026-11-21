<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OrderException;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 주류판매 자격 승인 (원본 다담다 admin/alcohol_seller_manage.php) */
class LiquorApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Vendor $vendor;
    private User $vendorUser;
    private Store $store;

    private array $beer = ['name' => '맥주', 'price' => 9000, 'stock_deduct_at' => 'order', 'is_alcohol' => '1', 'agree_prohibited' => '1'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'super_admin']);
        $this->vendor = Vendor::create(['name' => '아레나펍', 'state' => 'approved', 'commission_rate' => 10]);
        $this->vendorUser = User::factory()->create(['role' => 'vendor', 'vendor_id' => $this->vendor->id]);
        $this->store = Store::create(['vendor_id' => $this->vendor->id, 'code' => 'pub', 'name' => '아레나 펍', 'commission_rate' => 10]);
    }

    private function postBeer(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->vendorUser->fresh())->post(route('partner.products.store'), $this->beer);
    }

    public function test_alcohol_rejected_before_approval_even_with_license_no(): void
    {
        $this->postBeer()->assertSessionHasErrors('is_alcohol');

        // 예전 규칙(신고번호만 있으면 허용)은 이제 통하지 않는다
        $this->vendor->update(['liquor_license_no' => '제403-1호', 'liquor_status' => 'pending']);
        $this->postBeer()->assertSessionHasErrors('is_alcohol');
        $this->assertDatabaseMissing('products', ['name' => '맥주']);

        // 일반 상품은 그대로 등록된다
        $this->actingAs($this->vendorUser)->post(route('partner.products.store'), ['is_alcohol' => null] + $this->beer)
            ->assertRedirect(route('partner.products'));
    }

    public function test_update_cannot_turn_on_alcohol_before_approval(): void
    {
        $p = Product::create(['store_id' => $this->store->id, 'name' => '맥주', 'price' => 9000]);
        $this->actingAs($this->vendorUser)->put(route('partner.products.update', $p), $this->beer)->assertSessionHasErrors('is_alcohol');
        $this->assertFalse($p->fresh()->is_alcohol);
    }

    public function test_product_form_explains_why_and_how_to_apply(): void
    {
        $this->actingAs($this->vendorUser)->get(route('partner.products.create'))
            ->assertOk()->assertSee('주류판매 자격 신청')->assertSee(route('partner.liquor'));
    }

    public function test_vendor_applies_then_admin_approves_then_alcohol_allowed(): void
    {
        Storage::fake('local');
        $this->actingAs($this->vendorUser)->post(route('partner.liquor.request'), [
            'biz_type' => '일반음식점', 'liquor_license_no' => '제403-1호',
            'permit' => UploadedFile::fake()->create('permit.pdf', 100, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $v = $this->vendor->fresh();
        $this->assertSame('pending', $v->liquor_status);
        Storage::disk('local')->assertExists($v->documents['liquor_permit']);

        $this->actingAs($this->admin)->get(route('admin.liquor', ['status' => 'pending']))->assertOk()->assertSee('아레나펍');
        $this->actingAs($this->admin)->get(route('admin.vendors.document', [$v, 'liquor_permit']))->assertOk();

        $this->actingAs($this->admin)->put(route('admin.liquor.decide', $v), ['decision' => 'approved', 'biz_type' => '일반음식점', 'memo' => '영업신고증 확인'])
            ->assertSessionHasNoErrors();
        $v->refresh();
        $this->assertTrue($v->canSellLiquor());
        $this->assertSame($this->admin->id, $v->liquor_checked_by);
        $this->assertNotNull($v->liquor_checked_at);

        $this->postBeer()->assertRedirect(route('partner.products'));
        $this->assertTrue(Product::where('name', '맥주')->first()->is_alcohol);
    }

    public function test_cafe_cannot_be_approved_or_apply(): void
    {
        $this->actingAs($this->admin)->put(route('admin.liquor.decide', $this->vendor), ['decision' => 'approved', 'biz_type' => '카페'])
            ->assertSessionHasErrors('biz_type');
        $this->assertNull($this->vendor->fresh()->liquor_status);

        Storage::fake('local');
        $this->actingAs($this->vendorUser)->post(route('partner.liquor.request'), [
            'biz_type' => '카페', 'liquor_license_no' => '1', 'permit' => UploadedFile::fake()->create('p.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('biz_type');
    }

    public function test_reject_needs_reason_and_blocks_alcohol_orders(): void
    {
        $this->vendor->update(['liquor_status' => 'approved', 'liquor_biz_type' => '일반음식점']);
        $beer = Product::create(['store_id' => $this->store->id, 'name' => '맥주', 'price' => 9000, 'is_alcohol' => true]);

        $this->actingAs($this->admin)->put(route('admin.liquor.decide', $this->vendor), ['decision' => 'rejected', 'biz_type' => '일반음식점'])
            ->assertSessionHasErrors('memo');
        $this->actingAs($this->admin)->put(route('admin.liquor.decide', $this->vendor), ['decision' => 'rejected', 'biz_type' => '일반음식점', 'memo' => '영업신고증 업종이 휴게음식점'])
            ->assertSessionHasNoErrors();

        try {
            app(OrderService::class)->place($this->store->fresh(), [['product_id' => $beer->id, 'qty' => 1]], 'card', adultConfirmed: true);
            $this->fail('반려된 업체의 주류가 주문됨');
        } catch (OrderException $e) {
            $this->assertSame('liquor_not_allowed', $e->reason);
        }
    }

    public function test_vendor_and_guest_cannot_open_admin_screen(): void
    {
        $this->get(route('admin.liquor'))->assertRedirect();
        $this->put(route('admin.liquor.decide', $this->vendor), ['decision' => 'approved', 'biz_type' => '일반음식점'])->assertRedirect();

        $this->actingAs($this->vendorUser)->get(route('admin.liquor'))->assertForbidden();
        $this->actingAs($this->vendorUser)->put(route('admin.liquor.decide', $this->vendor), ['decision' => 'approved', 'biz_type' => '일반음식점'])->assertForbidden();
        $this->assertNull($this->vendor->fresh()->liquor_status);
    }
}
