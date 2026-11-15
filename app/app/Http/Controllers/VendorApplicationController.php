<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * A1 입점 신청 (입점계약 제3조): 사업자 정보 + 서류 4종 → 심사 중 → 운영사 승인 후 판매 시작.
 * 서류는 공개 경로가 아닌 로컬 디스크에 저장하고 운영사만 내려받는다.
 */
class VendorApplicationController extends Controller
{
    public function form(): View
    {
        return view('partner.apply', ['settings' => Setting::values()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $doc = ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'];
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'business_no' => ['required', 'regex:/^\d{3}-?\d{2}-?\d{5}$/'],
            'mail_order_no' => ['required', 'string', 'max:40'],
            'liquor_license_no' => ['nullable', 'string', 'max:40'],
            'representative' => ['required', 'string', 'max:30'],
            'address' => ['required', 'string', 'max:200'],
            'contact_phone' => ['required', 'string', 'max:20'],
            'contact_email' => ['required', 'email'],
            'bank_name' => ['required', 'string', 'max:30'],
            'bank_account' => ['required', 'string', 'max:40'],
            'account_holder' => ['required', 'string', 'max:50'],
            'login_email' => ['required', 'email', 'unique:users,email'],
            'login_password' => ['required', 'string', 'min:8', 'confirmed'],
            'docs.business_reg' => $doc,
            'docs.mail_order' => $doc,
            'docs.id_card' => $doc,
            'docs.bank_copy' => $doc,
            'agree_contract' => ['accepted'],
            'agree_prohibited' => ['accepted'],
        ], [
            'business_no.regex' => '사업자등록번호를 123-45-67890 형식으로 적어 주세요.',
            'mail_order_no.required' => '통신판매업 신고번호를 적어 주세요. 온라인 판매에 필요해요.',
            'login_email.unique' => '이미 쓰는 로그인 이메일이에요.',
            'login_password.confirmed' => '비밀번호 확인이 맞지 않아요.',
            'docs.*.required' => '서류를 모두 올려 주세요.',
            'docs.*.mimes' => 'PDF·JPG·PNG 파일만 올릴 수 있어요.',
            'docs.*.max' => '파일은 5MB 이하로 올려 주세요.',
            'agree_contract.accepted' => '입점 판매자 약관에 동의해 주세요.',
            'agree_prohibited.accepted' => '판매 금지 품목 안내를 확인해 주세요.',
        ]);

        $settings = Setting::values();

        $user = DB::transaction(function () use ($request, $data, $settings) {
            $vendor = Vendor::create(collect($data)->only([
                'name', 'business_no', 'mail_order_no', 'liquor_license_no', 'representative', 'address',
                'contact_phone', 'contact_email', 'bank_name', 'bank_account', 'account_holder',
            ])->all() + [
                'state' => 'pending',
                'commission_rate' => $settings['default_commission_rate'],
                'settlement_cycle' => $settings['default_settlement_cycle'],
                'settlement_day' => (int) $settings['default_settlement_day'],
                'payout_delay_days' => (int) $settings['default_payout_delay_days'],
            ]);

            $paths = [];
            foreach (array_keys(Vendor::DOCUMENTS) as $type) {
                $paths[$type] = $request->file("docs.$type")->store("vendor-docs/{$vendor->id}", 'local');
            }
            $vendor->update(['documents' => $paths]);

            return User::create([
                'role' => 'vendor', 'vendor_id' => $vendor->id, 'name' => $vendor->name,
                'email' => $data['login_email'], 'password' => $data['login_password'],
            ]);
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('partner.status');
    }

    public function status(Request $request): View|RedirectResponse
    {
        $vendor = $request->user()->vendor;
        if ($vendor->isActive()) {
            return redirect()->route('partner.board');
        }

        return view('partner.status', ['vendor' => $vendor]);
    }
}
