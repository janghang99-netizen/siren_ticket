<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketToken;
use App\Services\Audit;
use App\Services\Nft\NftTicketService;
use App\Services\OrderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** NFT 티켓: 공개 메타데이터·이미지 (마켓·지갑이 읽는 주소), 팬 입장 코드, 운영사 검표 */
class NftController extends Controller
{
    public function __construct(private NftTicketService $nft) {}

    /** ERC-721 tokenURI — 공연·좌석·상태만, 개인정보 없음 */
    public function metadata(string $tokenId): JsonResponse
    {
        $token = TicketToken::with('ticket.event', 'ticket.section')->where('token_id', $tokenId)->firstOrFail();
        $meta = $this->nft->metadata($token->ticket);
        $meta['attributes'][] = ['trait_type' => 'Status', 'value' => ucfirst($token->status)];

        return response()->json($meta)->header('Cache-Control', 'public, max-age=60');
    }

    /** 토큰 이미지: 공연 팬덤 색 그라데이션 티켓 (SVG, 외부 리소스 없음) */
    public function image(int $ticket): Response
    {
        $t = Ticket::with('event', 'section')->whereHas('token')->findOrFail($ticket);
        $e = $t->event;
        $esc = fn ($s) => htmlspecialchars((string) $s, ENT_XML1);
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="600" height="840" viewBox="0 0 600 840">
  <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
    <stop offset="0" stop-color="#FFB3D1"/><stop offset=".5" stop-color="{$esc($e->theme_color ?: '#C9B8FF')}"/><stop offset="1" stop-color="#8FC8FF"/>
  </linearGradient></defs>
  <rect width="600" height="840" rx="48" fill="url(#g)"/>
  <rect x="32" y="560" width="536" height="248" rx="28" fill="#fff" fill-opacity=".85"/>
  <text x="48" y="96" font-family="sans-serif" font-size="22" fill="#fff" fill-opacity=".8" letter-spacing="4">SIREN · OFFICIAL TICKET</text>
  <text x="48" y="190" font-family="sans-serif" font-size="64" font-weight="900" fill="#fff">{$esc($e->artist)}</text>
  <text x="48" y="240" font-family="sans-serif" font-size="26" fill="#fff">{$esc($e->name)}</text>
  <text x="48" y="290" font-family="sans-serif" font-size="24" fill="#fff" fill-opacity=".85">{$esc($e->starts_at->format('Y.m.d H:i'))} · {$esc($e->venue)}</text>
  <text x="64" y="640" font-family="sans-serif" font-size="22" fill="#6b6585">SECTION · ROW · SEAT</text>
  <text x="64" y="730" font-family="sans-serif" font-size="80" font-weight="900" fill="#17132B">{$esc($t->section->code)} · {$esc($t->rowName())} · {$esc($t->seat_no)}</text>
  <text x="64" y="782" font-family="sans-serif" font-size="20" fill="#6b6585">#{$esc($t->id)} · NON-TRANSFERABLE</text>
</svg>
SVG;

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** 팬 입장 코드 (본인 회원 티켓만). 화면이 25초마다 새로 받아 간다. */
    public function entryCode(Request $request, Ticket $ticket): JsonResponse
    {
        abort_unless($ticket->member_id && $ticket->member_id === $request->user('member')->id, 404);
        if ($ticket->status !== 'paid') {
            return response()->json(['code' => null, 'status' => $ticket->status]);
        }

        return response()->json(['code' => $this->nft->entryCode($ticket), 'refresh' => 25])->header('Cache-Control', 'no-store');
    }

    // ── 운영사 검표 ──

    public function checkInForm(): View
    {
        return view('admin.check-in', [
            'recent' => Ticket::with('event', 'section', 'token')->where('status', 'used')->latest('checked_in_at')->limit(20)->get(),
        ]);
    }

    public function checkIn(Request $request): RedirectResponse|JsonResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:120']])['code'];
        try {
            $ticket = $this->nft->checkIn($code);
        } catch (OrderException $e) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->withErrors(['code' => $e->getMessage()]);
        }
        Audit::log('ticket.checked_in', $ticket, "입장 {$ticket->ticket_no} · {$ticket->seatLabel()}");
        $msg = "입장 OK — {$ticket->event->artist} {$ticket->seatLabel()}";

        return $request->expectsJson() ? response()->json(['ok' => true, 'message' => $msg]) : back()->with('status', $msg);
    }
}
