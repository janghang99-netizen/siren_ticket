<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 관리 화면(운영사·업체)은 관리 로그인, 팬 화면(티켓 예매·내 계정)은 회원 로그인으로
        $middleware->redirectGuestsTo(fn ($request) => $request->is('admin', 'admin/*', 'partner', 'partner/*', 'gate', 'gate/*', 'logout')
            ? route('login')
            : route('member.login', ['return' => $request->getRequestUri()]));
        $middleware->redirectUsersTo(fn ($request) => $request->is('member/*')
            ? route('member.account')
            : \App\Http\Controllers\AuthController::home($request->user()));
        $middleware->web(append: [
            \App\Http\Middleware\EnsureMemberSession::class,
            // 관리 계정(운영사·업체·검표원): 비밀번호가 바뀌면 다른 기기에 남은 로그인을 끊는다
            \App\Http\Middleware\EnsureStaffSession::class,
        ]);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->append(\App\Http\Middleware\BlockSearchIndexing::class);   // SITE_NOINDEX=true 인 개발 서버: 검색 노출 금지
        // 공연 티켓 판매 기능 켜기/끄기 (슈퍼관리자 설정)
        $middleware->alias(['ticketing' => \App\Http\Middleware\EnsureTicketingEnabled::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 화면을 오래 열어 둬서 보안 토큰(CSRF)이 만료된 경우: 오류 페이지(419) 대신 그 화면으로 돌려보내 다시 시도하게
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson()) {
                return null;
            }

            return redirect()->back(fallback: url('/'))
                ->withInput($request->except(['password', 'password_confirmation', '_token']))
                ->withErrors(['email' => '화면이 오래 열려 있어 만료됐어요. 다시 시도해 주세요.', 'identifier' => '화면이 오래 열려 있어 만료됐어요. 다시 시도해 주세요.']);
        });
    })->create();
