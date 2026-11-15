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
        $middleware->redirectGuestsTo(fn ($request) => $request->is('admin', 'admin/*', 'partner', 'partner/*', 'logout')
            ? route('login')
            : route('member.login', ['return' => $request->getRequestUri()]));
        $middleware->redirectUsersTo(fn ($request) => $request->is('member/*')
            ? route('member.account')
            : \App\Http\Controllers\AuthController::home($request->user()));
        $middleware->web(append: [\App\Http\Middleware\EnsureMemberSession::class, \App\Http\Middleware\SetLocale::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
