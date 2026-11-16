<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 공연 티켓 판매 기능이 꺼져 있으면 티켓 관련 화면을 막는다.
 *  - 운영사 화면: 설정 화면으로 보내고 안내
 *  - 팬 화면: 그 공연의 매장 주문으로(공연이 있으면), 아니면 상시 매장 주문으로
 *  - API·JSON: 404
 */
class EnsureTicketingEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Setting::ticketingEnabled()) {
            return $next($request);
        }
        if ($request->expectsJson()) {
            abort(404);
        }
        if ($request->is('admin', 'admin/*')) {
            return redirect()->route('admin.settings')->with('status', '공연 티켓 판매 기능이 꺼져 있어요. 쓰려면 아래에서 켜 주세요.');
        }
        $event = $request->route('event');

        return $event instanceof \App\Models\Event
            ? redirect()->route('stores.index', $event)
            : redirect()->route('shop.index');
    }
}
