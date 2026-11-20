<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\BenefitLedger;
use App\Models\CapacitySlot;
use App\Models\Coupon;
use App\Models\CouponIssue;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Product;
use App\Models\SeatSection;
use App\Models\StampProgram;
use App\Models\Store;
use App\Models\Vendor;
use App\Services\Benefits\BenefitLedgerService;
use App\Services\Benefits\BenefitService;
use App\Services\OrderException;
use App\Services\OrderService;
use App\Services\SettlementService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 마일리지 · 쿠폰 · 스탬프 · 혜택 원장 (휴대폰 번호 기준, 매장 주문 + 공연 티켓) */
class BenefitTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;
    private Store $store;
    private Product $coffee;
    private Event $event;
    private CapacitySlot $slot;
    private Customer $fan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendor = Vendor::create(['name' => '아레나푸드', 'state' => 'approved']);
        $this->store = Store::create(['vendor_id' => $this->vendor->id, 'code' => 'arena1', 'name' => '커피', 'commission_rate' => 10]);
        $this->coffee = Product::create(['store_id' => $this->store->id, 'name' => '아메리카노', 'price' => 5000, 'stock' => 100, 'mileage_points' => 300]);
        $this->event = Event::create(['slug' => 'show', 'name' => '공연', 'artist' => '그룹', 'starts_at' => now()->addDays(20), 'sales_open_at' => now()->subDay(), 'max_per_buyer' => 4]);
        $this->slot = CapacitySlot::create(['event_id' => $this->event->id, 'store_id' => $this->store->id, 'slot' => 'pre', 'capacity' => 100]);
        $this->fan = Customer::forPhone('010-1234-5678');
    }

    private function giveMileage(int $amount): void
    {
        app(BenefitService::class)->adjustMileage($this->fan, $amount, '테스트 지급', \App\Models\User::factory()->create()->id);
        $this->fan->refresh();
    }

    private function order(int $qty = 1, ?int $coupon = null, int $mileage = 0)
    {
        $svc = app(OrderService::class);

        return $svc->confirmPayment($svc->place($this->store, [['product_id' => $this->coffee->id, 'qty' => $qty]], 'card', $this->slot->id,
            customer: $this->fan, couponIssueId: $coupon, mileageUse: $mileage));
    }

    private function pickUp($order)
    {
        $svc = app(OrderService::class);
        foreach ([OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::PickedUp] as $s) {
            $order = $svc->transition($order, $s);
        }

        return $order;
    }

    public function test_mileage_use_is_capped_and_cannot_go_negative(): void
    {
        $this->giveMileage(3000);
        $o = $this->order(2, mileage: 3000);
        $this->assertSame(7000, $o->paid_amount);
        $this->assertSame(0, $this->fan->fresh()->mileage_balance);

        try {
            $this->order(1, mileage: 1000);   // 잔액 0 — 원본은 동시 결제로 음수가 될 수 있었다
            $this->fail('잔액 없이 마일리지가 쓰였다');
        } catch (OrderException $e) {
            $this->assertSame('mileage_invalid', $e->reason);
        }
        $this->assertSame(0, $this->fan->fresh()->mileage_balance);
    }

    public function test_cancel_returns_mileage_and_coupon_exactly_once(): void
    {
        $this->giveMileage(2000);
        $coupon = Coupon::create(['name' => '천원 할인', 'issuer' => 'platform', 'scope' => 'order', 'kind' => 'fixed', 'value' => 1000, 'is_public' => true]);
        $issue = app(BenefitService::class)->claim($coupon, $this->fan);

        $o = $this->order(1, $issue->id, 2000);
        $this->assertSame([1000, 2000, 2000], [$o->coupon_discount, $o->mileage_used, $o->paid_amount]);
        $this->assertSame('used', $issue->fresh()->status);

        app(OrderService::class)->transition($o, OrderStatus::Cancelled);
        app(BenefitService::class)->releaseOrder($o->fresh());   // 재호출 흉내 — 두 번 돌려주면 안 됨

        $this->assertSame(2000, $this->fan->fresh()->mileage_balance);
        $this->assertSame('issued', $issue->fresh()->status);
        $this->assertSame(1, BenefitLedger::where('type', 'restore')->where('kind', 'mileage')->count());
    }

    public function test_pickup_earns_vendor_mileage_and_stamps_then_rewards_coupon(): void
    {
        $reward = Coupon::create(['name' => '아메리카노 무료', 'issuer' => 'vendor', 'vendor_id' => $this->vendor->id, 'scope' => 'order', 'store_id' => $this->store->id, 'kind' => 'free_item', 'product_id' => $this->coffee->id, 'source' => 'stamp', 'valid_days' => 60]);
        StampProgram::create(['owner' => 'store', 'store_id' => $this->store->id, 'name' => '커피 스탬프', 'need_count' => 3, 'reward_coupon_id' => $reward->id]);

        $this->pickUp($this->order(4));
        $this->assertSame(1200, $this->fan->fresh()->mileage_balance);   // 300 × 4 (상한 10% = 500 이내)
        $this->assertSame(1, $this->fan->stampCards()->first()->count);   // 4개 → 보상 1회, 1개 남음
        $free = CouponIssue::where('coupon_id', $reward->id)->first();
        $this->assertNotNull($free);

        // 받은 무료 쿠폰으로 주문 → 아메리카노 1개 값 할인, 업체 부담
        $o2 = $this->order(1, $free->id);
        $this->assertSame([5000, 0, 'vendor'], [$o2->coupon_discount, $o2->paid_amount, $o2->coupon_funded_by]);
    }

    public function test_revert_then_cancel_takes_back_earned_mileage_and_stamps(): void
    {
        // 수령완료(적립) → 되돌리기 → 취소를 되풀이해 적립금만 남기지 못한다
        StampProgram::create(['owner' => 'store', 'store_id' => $this->store->id, 'name' => '커피 스탬프', 'need_count' => 10]);
        $svc = app(OrderService::class);
        $o = $this->pickUp($this->order(2));
        $this->assertSame(600, $this->fan->fresh()->mileage_balance);

        foreach (range(1, 3) as $i) {
            $o = $svc->revert($o);   // 수령완료 → 준비완료 → 준비중 → 결제완료
        }
        $this->assertSame(OrderStatus::Paid, $o->status);
        $o = $svc->cancelByBuyer($o);

        $this->assertSame(OrderStatus::Refunded, $o->status);
        $this->assertSame(0, $this->fan->fresh()->mileage_balance);
        $this->assertSame(0, $this->fan->stampCards()->first()->count);
    }

    public function test_refund_after_pickup_takes_back_earned_mileage_and_stamps(): void
    {
        StampProgram::create(['owner' => 'store', 'store_id' => $this->store->id, 'name' => '커피 스탬프', 'need_count' => 10]);
        $o = $this->pickUp($this->order(2));
        $this->assertSame(600, $this->fan->fresh()->mileage_balance);

        app(OrderService::class)->adminRefund($o, '고객 불만');
        $this->assertSame(0, $this->fan->fresh()->mileage_balance);   // 원본은 회수하지 않았다
        $this->assertSame(0, $this->fan->stampCards()->first()->count);
    }

    public function test_settlement_charges_vendor_coupon_and_earned_mileage_but_not_platform_benefits(): void
    {
        $this->giveMileage(1000);
        $vc = Coupon::create(['name' => '업체 10%', 'issuer' => 'vendor', 'vendor_id' => $this->vendor->id, 'scope' => 'order', 'kind' => 'percent', 'value' => 10, 'is_public' => true]);
        $this->pickUp($this->order(2, app(BenefitService::class)->claim($vc, $this->fan)->id));   // 10,000 − 1,000 업체쿠폰, 적립 600
        $this->pickUp($this->order(1, mileage: 1000));                                              // 5,000 (운영사 마일리지 1,000), 적립 300

        $s = app(SettlementService::class)->generate(now()->subDay(), now())->first();
        $this->assertSame(15000, (int) $s->gross_amount);
        $this->assertSame(1000, (int) $s->coupon_vendor_amount);
        $this->assertSame(900, (int) $s->mileage_earn_amount);
        $this->assertSame(1000, (int) $s->platform_benefit_amount);
        // 수수료 10% × (9,000 + 5,000) = 1,400, 부가세 140
        $this->assertSame(1400, (int) $s->commission_amount);
        $this->assertSame(15000 - 1000 - 1400 - 140 - 900, (int) $s->payout_amount);
    }

    public function test_coupon_claim_is_one_per_phone_and_respects_limit(): void
    {
        $c = Coupon::create(['name' => '선착순', 'issuer' => 'platform', 'scope' => 'ticket', 'kind' => 'fixed', 'value' => 5000, 'is_public' => true, 'total_limit' => 1, 'code' => 'FAN5000']);
        app(BenefitService::class)->claimByCode('fan5000', $this->fan);

        foreach ([[$this->fan, 'coupon_duplicate'], [Customer::forPhone('01099998888'), 'coupon_closed']] as [$who, $reason]) {
            try {
                app(BenefitService::class)->claim($c->fresh(), $who);
                $this->fail('쿠폰이 한 번 더 발급됐다');
            } catch (OrderException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
        $this->assertSame(1, $c->fresh()->issued_count);
    }

    public function test_ticket_booking_splits_discount_and_partial_cancel_returns_share(): void
    {
        $section = SeatSection::create(['event_id' => $this->event->id, 'code' => 'A1', 'name' => 'A1', 'tier' => 'R', 'price' => 100000, 'capacity' => 10, 'ring' => 1, 'start_deg' => 0, 'end_deg' => 30]);
        $this->giveMileage(30000);
        $c = Coupon::create(['name' => '티켓 2만원', 'issuer' => 'platform', 'scope' => 'ticket', 'event_id' => $this->event->id, 'kind' => 'fixed', 'value' => 20000, 'is_public' => true]);
        $issue = app(BenefitService::class)->claim($c, $this->fan);

        $tickets = app(TicketService::class)->purchase($this->event, $section, 2, '팬', '010-1234-5678', now(), $issue->id, 30000);
        $this->assertSame([10000, 10000], $tickets->pluck('coupon_discount')->all());
        $this->assertSame([15000, 15000], $tickets->pluck('mileage_used')->all());
        $this->assertSame(150000, $tickets->sum('paid_amount'));
        $this->assertSame(0, $this->fan->fresh()->mileage_balance);

        // 20일 전 취소 → 수수료 0, 그 티켓 몫 마일리지 15,000 반환, 쿠폰은 남은 티켓이 있어 그대로
        $t = app(TicketService::class)->cancelByBuyer($tickets[0]);
        $this->assertSame(75000, $t->refund_amount);
        $this->assertSame(15000, $this->fan->fresh()->mileage_balance);
        $this->assertSame('used', $issue->fresh()->status);

        app(TicketService::class)->cancelByBuyer($tickets[1]);
        $this->assertSame('issued', $issue->fresh()->status);   // 예매 전체가 취소되면 쿠폰 반환
    }

    public function test_attendance_grants_ticket_mileage_and_platform_stamp_once(): void
    {
        $section = SeatSection::create(['event_id' => $this->event->id, 'code' => 'A1', 'name' => 'A1', 'tier' => 'R', 'price' => 100000, 'capacity' => 10, 'ring' => 1, 'start_deg' => 0, 'end_deg' => 30]);
        $reward = Coupon::create(['name' => '다음 공연 1만원', 'issuer' => 'platform', 'scope' => 'ticket', 'kind' => 'fixed', 'value' => 10000, 'source' => 'stamp']);
        StampProgram::create(['owner' => 'platform', 'name' => '관람 스탬프', 'need_count' => 2, 'reward_coupon_id' => $reward->id]);
        app(TicketService::class)->purchase($this->event, $section, 2, '팬', '01012345678');

        $this->event->update(['starts_at' => now()->subHours(4)]);
        $this->assertSame(2, app(BenefitService::class)->grantAttendance());
        $this->assertSame(0, app(BenefitService::class)->grantAttendance());
        $this->assertSame(2000, $this->fan->fresh()->mileage_balance);   // 1% × 100,000 × 2
        $this->assertSame(1, CouponIssue::where('coupon_id', $reward->id)->count());
    }

    public function test_ledger_hash_chain_detects_tampering_and_anchors_merkle_root(): void
    {
        $this->giveMileage(5000);
        $this->pickUp($this->order(1, mileage: 1000));
        $ledger = app(BenefitLedgerService::class);
        $this->assertTrue($ledger->verify()['ok']);

        $anchor = $ledger->anchor();
        $this->assertSame(BenefitLedger::count(), $anchor->row_count);
        $this->assertSame(64, strlen($anchor->merkle_root));
        $this->assertNull($ledger->anchor());   // 새 행이 없으면 만들지 않는다

        $second = BenefitLedger::orderBy('id')->skip(1)->value('id');
        DB::table('benefit_ledger')->where('id', $second)->update(['amount' => -1]);   // 누군가 DB를 고치면
        $this->assertSame(['ok' => false, 'checked' => 1, 'broken_at' => $second], $ledger->verify());
    }

    public function test_fan_verifies_phone_then_sees_benefits_and_orders_with_mileage(): void
    {
        $this->giveMileage(5000);
        $this->post(route('benefits.send'), ['phone' => '010-1234-5678']);
        $code = session('dev_code');
        $this->assertNotNull($code);
        $this->post(route('benefits.verify'), ['phone' => '01012345678', 'code' => $code])->assertRedirect(route('benefits'));
        $this->get(route('benefits'))->assertOk()->assertSee('5,000');

        $this->postJson(route('stores.order', [$this->event, $this->store]), [
            'slot_id' => $this->slot->id, 'agree_terms' => true, 'mileage_use' => 2000,
            'items' => [['product_id' => $this->coffee->id, 'qty' => 1]],
        ])->assertOk();
        $this->assertSame(3000, $this->fan->fresh()->mileage_balance);
    }

    public function test_admin_and_partner_benefit_screens_open_and_save(): void
    {
        $this->giveMileage(1000);
        $admin = \App\Models\User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->post(route('admin.coupons.store'), [
            'name' => '운영사 쿠폰', 'scope' => 'order', 'kind' => 'fixed', 'value' => 500, 'is_public' => 1, 'is_active' => 1,
        ])->assertRedirect();
        $coupon = Coupon::where('name', '운영사 쿠폰')->firstOrFail();
        $this->post(route('admin.coupons.grant', $coupon), ['phone' => '010-1234-5678', 'memo' => 'CS 보상'])->assertSessionHas('status');
        $this->put(route('admin.stamp.save'), ['need_count' => 5, 'reward_value' => 10000, 'reward_days' => 90, 'is_active' => 1])->assertSessionHas('status');
        $this->post(route('admin.customers.adjust', $this->fan), ['amount' => -500, 'memo' => '오적립 회수'])->assertSessionHas('status');
        $this->assertSame(500, $this->fan->fresh()->mileage_balance);
        foreach ([route('admin.coupons.index'), route('admin.coupons.show', $coupon), route('admin.coupons.create'), route('admin.coupons.edit', $coupon),
            route('admin.customers.index'), route('admin.customers.show', $this->fan), route('admin.ledger'), route('admin.settings')] as $url) {
            $this->get($url)->assertOk();
        }
        $this->post(route('admin.ledger.anchor'))->assertSessionHas('status');

        $vendorUser = \App\Models\User::factory()->create(['role' => 'vendor', 'vendor_id' => $this->vendor->id]);
        $this->actingAs($vendorUser)->post(route('partner.coupons.store'), [
            'name' => '우리 쿠폰', 'kind' => 'percent', 'value' => 10, 'max_discount' => 2000, 'is_public' => 1, 'is_active' => 1,
        ])->assertRedirect(route('partner.coupons'));
        $this->assertSame('vendor', Coupon::where('name', '우리 쿠폰')->value('issuer'));
        $this->put(route('partner.stamp.save'), ['need_count' => 8, 'reward_product_id' => $this->coffee->id, 'reward_days' => 30, 'is_active' => 1])->assertSessionHas('status');
        foreach ([route('partner.coupons'), route('partner.coupons.create'), route('partner.stamp'), route('partner.products.edit', $this->coffee)] as $url) {
            $this->get($url)->assertOk();
        }
        // 다른 업체 쿠폰은 못 고친다
        $this->get(route('partner.coupons.edit', $coupon))->assertForbidden();
    }

    public function test_benefits_need_verified_phone(): void
    {
        $this->postJson(route('stores.order', [$this->event, $this->store]), [
            'slot_id' => $this->slot->id, 'agree_terms' => true, 'mileage_use' => 1000,
            'items' => [['product_id' => $this->coffee->id, 'qty' => 1]],
        ])->assertStatus(422);
    }
}
