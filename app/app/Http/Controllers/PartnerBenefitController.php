<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SavesCoupons;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\StampCard;
use App\Models\StampProgram;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** 입점업체 혜택: 우리 쿠폰(업체 부담) · 매장 스탬프 (원본 my_stamp_config.php) */
class PartnerBenefitController extends PartnerController
{
    use SavesCoupons;

    public function coupons(Request $request): View
    {
        $vendorId = $request->user()->vendor_id;

        return view('partner.coupons', $this->shared($request) + [
            'coupons' => Coupon::with(['store', 'product'])->where('issuer', 'vendor')->where('vendor_id', $vendorId)
                ->where('source', 'manual')->latest('id')->get(),
        ]);
    }

    public function couponCreate(Request $request): View
    {
        return view('partner.coupon-form', $this->shared($request) + [
            'coupon' => new Coupon(['kind' => 'fixed', 'is_public' => true, 'is_active' => true]),
            'products' => $this->vendorProducts($request),
        ]);
    }

    public function couponEdit(Request $request, Coupon $coupon): View
    {
        $this->authorizeCoupon($request, $coupon);

        return view('partner.coupon-form', $this->shared($request) + ['coupon' => $coupon, 'products' => $this->vendorProducts($request)]);
    }

    public function couponStore(Request $request): RedirectResponse
    {
        $data = $this->couponData($request, null, $this->vendorStores($request)->pluck('id')->all(), false);
        $coupon = Coupon::create($data + ['issuer' => 'vendor', 'vendor_id' => $request->user()->vendor_id]);

        return redirect()->route('partner.coupons')->with('status', "‘{$coupon->name}’ 쿠폰을 만들었어요. 할인액은 정산에서 빠져요.");
    }

    public function couponUpdate(Request $request, Coupon $coupon): RedirectResponse
    {
        $this->authorizeCoupon($request, $coupon);
        $coupon->update($this->couponData($request, $coupon, $this->vendorStores($request)->pluck('id')->all(), false));

        return redirect()->route('partner.coupons')->with('status', "‘{$coupon->name}’ 쿠폰을 저장했어요.");
    }

    public function stamp(Request $request): View
    {
        $store = $this->currentStore($request);
        $program = StampProgram::with('rewardCoupon')->where('store_id', $store->id)->first();

        return view('partner.stamp', $this->shared($request) + [
            'program' => $program,
            'products' => Product::where('store_id', $store->id)->orderBy('sort')->get(),
            'members' => $program ? StampCard::where('stamp_program_id', $program->id)->count() : 0,
            'rewarded' => $program?->rewardCoupon ? $program->rewardCoupon->issued_count : 0,
            'used' => $program?->rewardCoupon ? $program->rewardCoupon->used_count : 0,
        ]);
    }

    /** 스탬프 설정 저장: 보상 상품을 고르면 그 상품 1개 무료 쿠폰(업체 부담)을 자동으로 만든다 */
    public function stampSave(Request $request): RedirectResponse
    {
        $store = $this->currentStore($request);
        $data = $request->validate([
            'need_count' => ['required', 'integer', 'min:1', 'max:50'],
            'min_amount' => ['nullable', 'integer', 'min:0'],
            'reward_product_id' => ['required', 'integer'],
            'reward_days' => ['required', 'integer', 'min:1', 'max:365'],
        ], ['reward_product_id.required' => '보상으로 줄 메뉴를 골라 주세요.']);
        $product = Product::where('store_id', $store->id)->findOrFail($data['reward_product_id']);

        $program = StampProgram::firstOrNew(['store_id' => $store->id], ['owner' => 'store']);
        $reward = $program->rewardCoupon ?? new Coupon(['issuer' => 'vendor', 'vendor_id' => $store->vendor_id, 'scope' => 'order', 'kind' => 'free_item', 'source' => 'stamp']);
        $reward->fill([
            'name' => "{$store->name} 스탬프 보상", 'store_id' => $store->id, 'product_id' => $product->id,
            'valid_days' => $data['reward_days'], 'is_active' => true,
        ])->save();
        $program->fill([
            'owner' => 'store', 'name' => "{$store->name} 스탬프", 'need_count' => $data['need_count'],
            'min_amount' => (int) ($data['min_amount'] ?? 0), 'is_active' => $request->boolean('is_active'), 'reward_coupon_id' => $reward->id,
        ])->save();

        return back()->with('status', '스탬프 설정을 저장했어요.');
    }

    private function authorizeCoupon(Request $request, Coupon $coupon): void
    {
        abort_unless($coupon->issuer === 'vendor' && $coupon->vendor_id === $request->user()->vendor_id && $coupon->source === 'manual', 403);
    }

    private function vendorProducts(Request $request)
    {
        return Product::with('store')->whereIn('store_id', $this->vendorStores($request)->pluck('id'))->orderBy('store_id')->orderBy('sort')->get();
    }
}
