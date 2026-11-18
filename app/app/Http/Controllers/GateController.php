<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Audit;
use App\Services\Nft\NftTicketService;
use App\Services\OrderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 공연장 입구 검표 — 검표원 휴대폰 카메라로 관객 화면의 QR(30초마다 바뀜)을 연속으로 찍는다.
 *  - 입장은 DB 에서 바로 확정 (한 사람 0.1초 안팎), 블록체인 ‘사용됨’ 기록은 응답 뒤에 한 줄로 보낸다.
 *  - 검표원이 고른 공연의 티켓만 통과, 누가·어느 게이트에서 입장시켰는지 남긴다.
 *  - 검표원 계정 관리는 운영사(슈퍼관리자)만.
 */
class GateController extends Controller
{
    public function __construct(private NftTicketService $nft) {}

    /** 휴대폰 검표 화면 (검표원·운영사) */
    public function scanner(Request $request): View
    {
        $events = Event::where('starts_at', '>=', now()->subHours(12))->where('starts_at', '<=', now()->addDays(60))
            ->orderBy('starts_at')->limit(30)->get()
            ->reject(fn (Event $e) => $e->isCancelled())->values();

        return view('gate.scanner', [
            'events' => $events->map(fn (Event $e) => [
                'id' => $e->id, 'label' => "{$e->artist} · ".$e->starts_at->format('m.d H:i'),
                'today' => $e->starts_at->isToday(),
            ]),
            'gate' => $request->user()->gate_name,
        ]);
    }

    /** 코드 한 장 검표 — 카메라·블루투스 스캐너·직접 입력 공용 */
    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:120'],
            'event_id' => ['nullable', 'integer'],
            'gate' => ['nullable', 'string', 'max:40'],
        ]);
        $user = $request->user();
        $gate = trim((string) ($data['gate'] ?? '')) ?: $user->gate_name;
        try {
            $ticket = $this->nft->checkIn($data['code'], null, $data['event_id'] ?? null, $user->id, $gate);
        } catch (OrderException $e) {
            return response()->json(['ok' => false, 'reason' => $e->reason, 'message' => $e->getMessage(), 'msg' => $e->getMessage()],
                $e->reason === 'already_used' ? 409 : 422);
        }
        Audit::log('ticket.checked_in', $ticket, "입장 {$ticket->ticket_no} · {$ticket->seatLabel()}".($gate ? " · {$gate}" : ''));

        // 블록체인 ‘사용됨’ 기록은 응답을 보낸 뒤에 (잠금으로 한 줄씩 — 동시에 여러 게이트가 찍어도 기다리지 않음)
        if ($ticket->token?->mint_tx) {
            dispatch(fn () => app(NftTicketService::class)->flushUsed(50))->afterResponse();
        }

        return response()->json([
            'ok' => true,
            'message' => "입장 OK — {$ticket->event->artist} {$ticket->seatLabel()}",
            'seat' => $ticket->seatLabel(),
            'tier' => $ticket->section->tier,
            'artist' => $ticket->event->artist,
            'stats' => $this->nft->entryStats($ticket->event_id),
        ]);
    }

    /** 공연 입장 현황 (게이트 전체 합) — 검표 화면이 15초마다 받아 간다 */
    public function stats(Event $event): JsonResponse
    {
        return response()->json($this->nft->entryStats($event->id))->header('Cache-Control', 'no-store');
    }

    // ── 검표원 계정 (운영사) ──

    public function staff(): View
    {
        $staff = User::where('role', 'gate')->orderBy('name')->get();
        $counts = Ticket::whereIn('checked_in_by', $staff->pluck('id'))->whereDate('checked_in_at', today())
            ->selectRaw('checked_in_by, COUNT(*) AS n')->groupBy('checked_in_by')->pluck('n', 'checked_in_by');

        return view('admin.gate-staff', ['staff' => $staff, 'today' => $counts]);
    }

    public function staffStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:120', Rule::unique('users', 'email')],
            'gate_name' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
        ]);
        $user = new User(['name' => $data['name'], 'email' => Str::lower($data['email']), 'role' => 'gate', 'gate_name' => $data['gate_name'] ?? null]);
        $user->password = $data['password'];
        $user->save();
        Audit::log('gate.staff_created', $user, "검표원 {$user->name} ({$user->email})");

        return back()->with('status', "{$user->name} 검표원 계정을 만들었어요. 휴대폰에서 로그인하면 검표 화면이 열려요.");
    }

    public function staffUpdate(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->isGate(), 404);
        $data = $request->validate(['gate_name' => ['nullable', 'string', 'max:40'], 'password' => ['nullable', 'string', 'min:8', 'max:72']]);
        $user->gate_name = $data['gate_name'] ?? null;
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        return back()->with('status', "{$user->name} 정보를 바꿨어요.");
    }

    public function staffDestroy(User $user): RedirectResponse
    {
        abort_unless($user->isGate(), 404);
        Audit::log('gate.staff_deleted', $user, "검표원 {$user->name} ({$user->email}) 삭제");
        $user->delete();

        return back()->with('status', '검표원 계정을 지웠어요. (입장 기록은 남아요)');
    }
}
