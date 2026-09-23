<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-токен для server-to-server вызовов Lead Control → Desk (запись в КП).
 * Не путать с DESK_API_TOKEN (CRM1 Desk API на Lead Control).
 */
class VerifyDeskInternalToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('desk.internal_token', '');
        if ($expected === '') {
            return response()->json([
                'ok' => false,
                'code' => 'internal_write_disabled',
                'message' => 'Внутренняя запись Desk не настроена (DESK_INTERNAL_TOKEN).',
            ], 503);
        }

        $auth = (string) $request->header('Authorization', '');
        $token = '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1) {
            $token = trim($m[1]);
        }

        if ($token === '' || ! hash_equals($expected, $token)) {
            return response()->json([
                'ok' => false,
                'code' => 'unauthorized',
                'message' => 'Unauthorized',
            ], 401);
        }

        return $next($request);
    }
}
