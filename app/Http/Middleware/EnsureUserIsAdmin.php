<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * 管理者（role = 2）以外のアクセスを拒否する。
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ((int) $request->user()?->role !== 2) {
            abort(403);
        }

        return $next($request);
    }
}
