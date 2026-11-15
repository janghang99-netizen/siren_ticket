<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** 주문 규칙 위반. 원본 ajax 응답과 같은 {result:'fail', msg} 모양으로 422 반환. */
class OrderException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'invalid', public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(\Illuminate\Http\Request $request): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        // 폼 화면(관리 콘솔 등)에서는 이전 화면으로 돌아가 메시지를 보여 준다
        if (! $request->expectsJson()) {
            return back()->withInput()->withErrors(['rule' => $this->getMessage()]);
        }

        return response()->json([
            'result' => 'fail',
            'code' => $this->reason,
            'msg' => $this->getMessage(),
        ], $this->status);
    }
}
