<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\StoreTerminal;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 매장 현장 카드 단말기 관리 — 업체(자기 매장 모두)와 운영사(모든 매장)가 같이 쓴다.
 * 등록 · 이름/밴사 수정 · 사용 중지/다시 사용 · 삭제(결제 기록이 있으면 삭제 대신 사용 중지).
 */
class TerminalController extends Controller
{
    /** 업체: 내 매장 모두의 단말기 */
    public function index(Request $request): View
    {
        $stores = Store::where('vendor_id', $request->user()->vendor_id)->orderBy('id')->get();
        $terminals = StoreTerminal::whereIn('store_id', $stores->pluck('id'))->orderBy('store_id')->orderBy('id')->get()->groupBy('store_id');

        return view('partner.terminals', [
            'stores' => $stores, 'terminals' => $terminals, 'providers' => StoreTerminal::PROVIDERS,
            'store' => $stores->firstWhere('id', (int) $request->session()->get('partner_store_id')) ?? $stores->first(),
            'vendor' => $request->user()->vendor,
        ]);
    }

    public function store(Request $request, ?Store $store = null): RedirectResponse
    {
        $data = $request->validate([
            'store_id' => [$store ? 'nullable' : 'required', 'integer'],
            'provider' => ['required', 'string', 'max:30'],
            'tid' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-_]+$/', 'unique:store_terminals,tid'],
            'label' => ['nullable', 'string', 'max:60'],
        ], [
            'store_id.required' => '매장을 골라 주세요.',
            'tid.required' => '단말기 ID(TID)를 적어 주세요.', 'tid.unique' => '이미 등록된 단말기 ID 예요.',
            'tid.regex' => '단말기 ID 는 영문·숫자·-·_ 만 쓸 수 있어요.', 'provider.required' => '밴사를 적어 주세요.',
        ]);
        $storeId = $store?->id ?? (int) $data['store_id'];
        $this->authorizeStore($request, $storeId);

        $terminal = StoreTerminal::create([
            'store_id' => $storeId, 'provider' => strtoupper(trim($data['provider'])), 'tid' => trim($data['tid']),
            'label' => filled($data['label'] ?? null) ? trim($data['label']) : null, 'is_active' => true,
        ]);
        Audit::log('terminal.created', $terminal, "단말기 등록 · {$terminal->store->name} · {$terminal->provider} {$terminal->tid}");

        return back()->with('status', "{$terminal->store->name}에 단말기 {$terminal->tid}을(를) 등록했어요.");
    }

    public function update(Request $request, StoreTerminal $terminal): RedirectResponse
    {
        $this->authorizeStore($request, $terminal->store_id);
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:30'],
            'label' => ['nullable', 'string', 'max:60'],
        ]);
        $terminal->update(['provider' => strtoupper(trim($data['provider'])), 'label' => filled($data['label'] ?? null) ? trim($data['label']) : null]);
        Audit::log('terminal.updated', $terminal, "단말기 수정 · {$terminal->tid}");

        return back()->with('status', "단말기 {$terminal->tid}을(를) 저장했어요.");
    }

    public function toggle(Request $request, StoreTerminal $terminal): RedirectResponse
    {
        $this->authorizeStore($request, $terminal->store_id);
        $terminal->update(['is_active' => ! $terminal->is_active]);
        Audit::log('terminal.toggled', $terminal, "단말기 {$terminal->tid} ".($terminal->is_active ? '다시 사용' : '사용 중지'));

        return back()->with('status', "단말기 {$terminal->tid}을(를) ".($terminal->is_active ? '다시 써요.' : '사용 중지했어요. 현장판매에서 고를 수 없어요.'));
    }

    public function destroy(Request $request, StoreTerminal $terminal): RedirectResponse
    {
        $this->authorizeStore($request, $terminal->store_id);
        if ($terminal->hasOrders()) {
            $terminal->update(['is_active' => false]);

            return back()->with('status', "단말기 {$terminal->tid}은(는) 결제 기록이 있어 지우지 않고 사용 중지했어요. (매출 대사 기록 보존)");
        }
        $tid = $terminal->tid;
        Audit::log('terminal.deleted', $terminal, "단말기 삭제 · {$tid}");
        $terminal->delete();

        return back()->with('status', "단말기 {$tid}을(를) 지웠어요.");
    }

    /** 운영사는 모든 매장, 업체는 자기 매장만 */
    private function authorizeStore(Request $request, int $storeId): void
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || Store::whereKey($storeId)->where('vendor_id', $user->vendor_id)->exists(), 403);
    }
}
