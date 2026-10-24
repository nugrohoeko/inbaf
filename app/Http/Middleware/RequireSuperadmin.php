<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireSuperadmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->role === 'superadmin' && $request->user()?->active, 403);
        return $next($request);
    }
}
