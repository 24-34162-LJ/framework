<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Middleware/RoleMiddleware.php
 * Feature: Security
 * Purpose: Blocks a route unless the logged-in user has the required role (admin, staff or client).
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = Auth::user();

        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->status !== 'active') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        abort_unless(in_array($user->role, $roles, true), 403);

        return $next($request);
    }
}
