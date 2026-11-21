<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 운영사: 주류판매 자격 관리 (원본 다담다 admin/alcohol_seller_manage.php, 슈퍼관리자 전용)
 *
 * 업체가 사업자등록증·영업신고증으로 영업형태를 증명하면 운영사가 확인해 승인/반려한다.
 * 승인된 업체만 상품 등록·수정에서 '주류'를 켤 수 있고(PartnerController::saveProduct),
 * 주류 주문도 승인된 업체 매장에서만 받는다(OrderService).
 */
class LiquorController extends Controller
{
    public function index(Request $request): View
    {
        $filter = (string) $request->query('status', 'all');
        $counts = ['all' => Vendor::count(), 'none' => Vendor::whereNull('liquor_status')->count()]
            + collect(Vendor::LIQUOR_STATES)->map(fn ($l, $s) => Vendor::where('liquor_status', $s)->count())->all();

        $vendors = Vendor::with('liquorChecker')
            ->withCount(['stores as alcohol_products_count' => fn ($q) => $q->join('products', 'products.store_id', '=', 'stores.id')->where('products.is_alcohol', true)])
            ->when($filter === 'none', fn ($q) => $q->whereNull('liquor_status'))
            ->when(array_key_exists($filter, Vendor::LIQUOR_STATES), fn ($q) => $q->where('liquor_status', $filter))
            // 확인 대기가 맨 위, 그다음 승인·반려·신청 없음
            ->orderByRaw("case liquor_status when 'pending' then 0 when 'approved' then 1 when 'rejected' then 2 else 3 end")
            ->orderByDesc('liquor_requested_at')->orderBy('name')
            ->paginate(30)->withQueryString();

        return view('admin.liquor', [
            'vendors' => $vendors, 'filter' => $filter, 'counts' => $counts,
            'states' => Vendor::LIQUOR_STATES, 'bizTypes' => Vendor::LIQUOR_BIZ_TYPES,
        ]);
    }

    /** 서류 확인 후 승인 / 반려 */
    public function decide(Request $request, Vendor $vendor): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'biz_type' => ['required', Rule::in(array_keys(Vendor::LIQUOR_BIZ_TYPES))],
            'memo' => [Rule::requiredIf(fn () => $request->input('decision') === 'rejected' || $request->input('biz_type') === '기타'), 'nullable', 'string', 'max:255'],
        ], [
            'biz_type.required' => '서류에서 확인한 영업형태를 골라 주세요.',
            'memo.required' => '반려 사유나 확인한 서류 내용을 메모에 적어 주세요.',
        ]);

        if ($data['decision'] === 'approved' && ! Vendor::LIQUOR_BIZ_TYPES[$data['biz_type']]['allowed']) {
            throw ValidationException::withMessages(['biz_type' => Vendor::LIQUOR_BIZ_TYPES[$data['biz_type']]['guide'].' 승인 대신 반려해 주세요.']);
        }

        $vendor->update([
            'liquor_status' => $data['decision'],
            'liquor_biz_type' => $data['biz_type'],
            'liquor_memo' => $data['memo'] ?? null,
            'liquor_checked_at' => now(),
            'liquor_checked_by' => $request->user()->id,
        ]);

        $label = $data['decision'] === 'approved' ? '승인' : '반려';
        Audit::log('vendor.liquor_'.$data['decision'], $vendor,
            "‘{$vendor->name}’ 주류판매 자격 {$label} ({$data['biz_type']})".(empty($data['memo']) ? '' : ': '.$data['memo']));

        return back()->with('status', $data['decision'] === 'approved'
            ? "‘{$vendor->name}’ 업체의 주류 판매를 승인했어요. 이제 상품에서 ‘주류’를 켤 수 있어요."
            : "‘{$vendor->name}’ 업체의 주류 판매를 반려했어요. 이 업체 매장의 주류 상품은 주문을 받지 않아요.");
    }
}
