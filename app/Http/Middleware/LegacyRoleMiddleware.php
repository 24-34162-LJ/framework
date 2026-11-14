<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class LegacyRoleMiddleware
{
    /**
     * Preserve the page-level legacy redirect used for inactive or mismatched accounts.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = Auth::user();
        if (! $user || $user->status !== 'active' || ! in_array($user->role, $roles, true)) {
            if ($user && $user->status !== 'active') {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect('/login.php');
        }

        return $next($request);
    }
}
