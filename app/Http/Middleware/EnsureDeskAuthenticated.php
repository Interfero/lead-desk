<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeskAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->has('desk_user')) {
            return redirect()->route('hub.login');
        }

        return $next($request);
    }
}
