<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** 운영사·업체 쿠폰 등록/수정 공통 검증 */
trait SavesCoupons
{
    /**
     * @param  array<int, int>  $storeIds  이 사용자가 고를 수 있는 매장 (업체는 자기 매장만)
     * @return array<string, mixed>
     */
    protected function couponData(Request $request, ?Coupon $coupon, array $storeIds, bool $platform): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'scope' => [$platform ? 'required' : 'nullable', 'in:order,ticket'],
            'kind' => ['required', 'in:fixed,percent,free_item'],
            'value' => ['required_unless:kind,free_item', 'nullable', 'integer', 'min:0', Rule::when($request->input('kind') === 'percent', ['max:100'])],
            'max_discount' => ['nullable', 'integer', 'min:0'],
            'min_amount' => ['nullable', 'integer', 'min:0'],
            'store_id' => ['nullable', 'integer', Rule::in($storeIds)],
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
            'product_id' => ['required_if:kind,free_item', 'nullable', 'integer'],
            'code' => ['nullable', 'alpha_dash', 'max:30', Rule::unique('coupons', 'code')->ignore($coupon?->id)],
            'is_public' => ['boolean'],
            'is_active' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'valid_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'total_limit' => ['nullable', 'integer', 'min:1'],
        ], [
            'name.required' => '쿠폰 이름을 적어 주세요.',
            'product_id.required_if' => '무료로 줄 상품을 골라 주세요.',
            'code.unique' => '이미 쓰는 쿠폰 코드예요.',
            'store_id.in' => '고를 수 없는 매장이에요.',
        ]);

        $data['scope'] = $platform ? $data['scope'] : 'order';
        if ($data['scope'] === 'ticket') {
            $data['store_id'] = null;
            if ($data['kind'] === 'free_item') {
                abort(422, '공연 티켓 쿠폰은 정액·정률만 돼요.');
            }
        } else {
            $data['event_id'] = null;
        }
        if ($data['kind'] === 'free_item') {
            $product = Product::whereIn('store_id', $storeIds)->findOrFail($data['product_id']);
            $data['store_id'] = $product->store_id;   // 무료 상품 쿠폰은 그 상품의 매장에서만
            $data['value'] = 0;
        } else {
            $data['product_id'] = null;
        }
        $data['code'] = filled($data['code'] ?? null) ? strtoupper($data['code']) : null;
        $data['is_public'] = $request->boolean('is_public');
        $data['is_active'] = $request->boolean('is_active');
        $data['min_amount'] = (int) ($data['min_amount'] ?? 0);
        $data['value'] = (int) ($data['value'] ?? 0);

        return $data;
    }
}
