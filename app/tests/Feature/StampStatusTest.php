<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Member;
use App\Models\StampCard;
use App\Models\StampProgram;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Benefits\BenefitLedgerService;
use App\Services\Benefits\BenefitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 운영사 회원별 스탬프 현황 (원본 admin/stamp_status.php 회원별 탭) */
class StampStatusTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;
    private Store $coffee;
    private Store $bakery;
    private Customer $kim;
    private Customer $lee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendor = Vendor::create(['name' => '아레나푸드', 'state' => 'approved']);
        $this->coffee = Store::create(['vendor_id' => $this->vendor->id, 'code' => 'coffee1', 'name' => '커피집', 'commission_rate' => 10]);
        $this->bakery = Store::create(['vendor_id' => $this->vendor->id, 'code' => 'bread1', 'name' => '빵집', 'commission_rate' => 10]);

        $reward = Coupon::create(['name' => '아메리카노 무료', 'issuer' => 'vendor', 'vendor_id' => $this->vendor->id, 'scope' => 'order', 'store_id' => $this->coffee->id, 'kind' => 'fixed', 'value' => 4000, 'source' => 'stamp', 'valid_days' => 60]);
        $coffeeProgram = StampProgram::create(['owner' => 'store', 'store_id' => $this->coffee->id, 'name' => '커피 스탬프', 'need_count' => 10, 'reward_coupon_id' => $reward->id]);
        $breadProgram = StampProgram::create(['owner' => 'store', 'store_id' => $this->bakery->id, 'name' => '빵 스탬프', 'need_count' => 5]);

        $this->kim = Customer::forPhone('010-1111-2222');
        $this->lee = Customer::forPhone('010-3333-4444');
        $this->member('김철수', $this->kim);
        $this->member('이영희', $this->lee);

        StampCard::create(['stamp_program_id' => $coffeeProgram->id, 'customer_id' => $this->kim->id, 'count' => 3, 'total_earned' => 23]);
        StampCard::create(['stamp_program_id' => $breadProgram->id, 'customer_id' => $this->lee->id, 'count' => 2, 'total_earned' => 2]);

        // 김철수는 커피 스탬프 보상 쿠폰을 두 번 받았다
        $benefits = app(BenefitService::class);
        $benefits->grant($reward, $this->kim, '스탬프 보상');
        $benefits->grant($reward, $this->kim, '스탬프 보상');
        app(BenefitLedgerService::class)->append($this->kim, 'stamp', 'earn', 1, 3, $coffeeProgram->id, 'order:T1', '커피 스탬프');
    }

    private function member(string $name, Customer $customer): Member
    {
        $m = new Member(['name' => $name, 'phone' => $customer->phone, 'customer_id' => $customer->id]);
        $m->forceFill(['password' => 'secret-password'])->save();

        return $m;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    public function test_admin_sees_every_stamp_holder_with_masked_name_and_phone(): void
    {
        $res = $this->actingAs($this->admin())->get(route('admin.coupons.stamps'))->assertOk();

        $res->assertSee('김*수')->assertSee('이*희')
            ->assertSee('010-****-2222')->assertSee('010-****-4444')
            ->assertSee('커피집')->assertSee('빵집')
            ->assertSee(route('admin.customers.show', $this->kim), false)
            ->assertDontSee('김철수')->assertDontSee('01011112222');

        $kimCard = $res->viewData('cards')->firstWhere('customer_id', $this->kim->id);
        $this->assertSame(2, (int) $kimCard->rewards_count);
        $this->assertNotNull($kimCard->last_earned_at);
        $leeCard = $res->viewData('cards')->firstWhere('customer_id', $this->lee->id);
        $this->assertSame(0, (int) $leeCard->rewards_count);
        $this->assertNull($leeCard->last_earned_at);
    }

    public function test_search_by_name_phone_and_store(): void
    {
        $admin = $this->admin();
        $ids = fn ($res) => $res->viewData('cards')->pluck('customer_id')->all();

        $this->assertSame([$this->kim->id], $ids($this->actingAs($admin)->get(route('admin.coupons.stamps', ['q' => '철수']))->assertOk()));
        $this->assertSame([$this->lee->id], $ids($this->actingAs($admin)->get(route('admin.coupons.stamps', ['q' => '4444']))->assertOk()));
        $this->assertSame([$this->lee->id], $ids($this->actingAs($admin)->get(route('admin.coupons.stamps', ['store' => $this->bakery->id]))->assertOk()));
        $this->assertSame([], $ids($this->actingAs($admin)->get(route('admin.coupons.stamps', ['q' => '없는사람']))->assertOk()));
    }

    public function test_list_pages_by_thirty(): void
    {
        $program = StampProgram::where('store_id', $this->bakery->id)->first();
        foreach (range(1, 30) as $i) {
            StampCard::create(['stamp_program_id' => $program->id, 'customer_id' => Customer::forPhone('0109000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT))->id, 'count' => 1, 'total_earned' => 1]);
        }
        $admin = $this->admin();

        $first = $this->actingAs($admin)->get(route('admin.coupons.stamps'))->assertOk();
        $this->assertCount(30, $first->viewData('cards'));
        $this->assertSame(32, $first->viewData('cards')->total());
        $this->assertCount(2, $this->actingAs($admin)->get(route('admin.coupons.stamps', ['page' => 2]))->assertOk()->viewData('cards'));
    }

    public function test_coupon_page_links_to_stamp_tab(): void
    {
        $this->actingAs($this->admin())->get(route('admin.coupons.index'))->assertOk()
            ->assertSee(route('admin.coupons.stamps'), false)->assertSee('회원별 스탬프');
    }

    public function test_vendor_and_guest_cannot_open_stamp_status(): void
    {
        $vendorUser = User::factory()->create(['role' => 'vendor', 'vendor_id' => $this->vendor->id]);
        $this->actingAs($vendorUser)->get(route('admin.coupons.stamps'))->assertForbidden();

        auth()->logout();
        $this->get(route('admin.coupons.stamps'))->assertRedirect(route('login'));
    }
}
