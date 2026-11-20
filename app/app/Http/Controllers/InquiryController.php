<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Store;
use App\Services\Audit;
use App\Services\Benefits\SmsSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 1:1 문의 (원본 happy_inquiry).
 *  - 고객: 로그인한 회원 또는 휴대폰 인증한 고객만 쓴다 (답변 알림을 받을 번호가 있어야 한다).
 *  - 운영사: 답변을 달면 고객에게 문자로 알린다.
 */
class InquiryController extends Controller
{
    public function index(Request $request): View
    {
        $customer = $this->customer($request);

        return view('fan.inquiries', [
            'customer' => $customer,
            'inquiries' => $customer ? Inquiry::with('store')->where('customer_id', $customer->id)->latest()->get() : collect(),
            'stores' => Store::whereHas('vendor', fn ($q) => $q->where('state', 'approved'))->orderBy('name')->get(['id', 'name']),
            'orders' => $customer ? Order::with('store:id,name')->where('customer_id', $customer->id)->latest()->limit(20)->get(['id', 'order_no', 'store_id', 'total_amount', 'created_at']) : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = $this->customer($request) ?? abort(403);
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(Inquiry::CATEGORIES))],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'order_no' => ['nullable', 'string', 'max:40'],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:3000'],
        ], ['title.required' => '제목을 적어 주세요.', 'body.required' => '문의 내용을 적어 주세요.']);
        // 주문번호는 내 주문만 연결한다
        if (! empty($data['order_no']) && ! Order::where('customer_id', $customer->id)->where('order_no', $data['order_no'])->exists()) {
            $data['order_no'] = null;
        }

        $inquiry = Inquiry::create($data + ['customer_id' => $customer->id, 'member_id' => $request->user('member')?->id]);

        return redirect()->route('inquiries.show', $inquiry)->with('status', '문의를 남겼어요. 답변이 달리면 문자로 알려 드릴게요.');
    }

    public function show(Request $request, Inquiry $inquiry): View
    {
        $customer = $this->customer($request);
        abort_unless($customer && $inquiry->customer_id === $customer->id, 404);

        return view('fan.inquiry', ['inquiry' => $inquiry->load('store')]);
    }

    // ── 운영사 ──

    public function adminIndex(Request $request): View
    {
        $status = $request->query('status', 'open');

        return view('admin.inquiries', [
            'status' => $status,
            'inquiries' => Inquiry::with(['customer', 'store'])->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->latest()->paginate(30)->withQueryString(),
            'openCount' => Inquiry::where('status', 'open')->count(),
        ]);
    }

    public function adminShow(Inquiry $inquiry): View
    {
        return view('admin.inquiry', [
            'inquiry' => $inquiry->load(['customer', 'store', 'answeredBy']),
            'order' => $inquiry->order_no ? Order::where('order_no', $inquiry->order_no)->first() : null,
        ]);
    }

    public function answer(Request $request, Inquiry $inquiry, SmsSender $sms): RedirectResponse
    {
        $data = $request->validate(['answer' => ['required', 'string', 'max:3000']], ['answer.required' => '답변을 적어 주세요.']);
        $first = ! $inquiry->isAnswered();
        $inquiry->forceFill(['answer' => $data['answer'], 'status' => 'answered', 'answered_by' => $request->user()->id, 'answered_at' => now()])->save();
        Audit::log('inquiry.answer', $inquiry, "1:1 문의 #{$inquiry->id} 답변");

        if ($first && ($phone = $inquiry->customer?->smsPhone())) {
            try {
                $sms->send($phone, '['.Setting::brand().'] 1:1 문의에 답변이 달렸어요. '.route('inquiries.show', $inquiry));
            } catch (\Throwable $e) {
                report($e);   // 문자 실패해도 답변은 저장 (고객은 문의 화면에서 본다)
            }
        }

        return back()->with('status', $first ? '답변을 저장하고 고객에게 문자로 알렸어요.' : '답변을 고쳤어요. (문자는 처음 답변 때만 보내요)');
    }

    /** 문의하는 고객: 로그인 회원의 고객, 또는 휴대폰 인증한 고객 (FanController 와 같은 규칙) */
    private function customer(Request $request): ?Customer
    {
        if ($member = $request->user('member')) {
            return $member->customer;
        }
        $phone = $request->session()->get('verified_phone');
        if (! $phone || $request->session()->get('verified_until', 0) < now()->timestamp) {
            return null;
        }

        return Customer::where('phone', $phone)->first();
    }
}
