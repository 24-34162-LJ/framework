<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Middleware/LegacyCsrfToken.php
 * Feature: Security
 * Purpose: Accepts the CSRF token field/header names used by the old PHP pages, then runs Laravel's
 *          normal CSRF check.
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LegacyCsrfToken
{
    /**
     * Adapt the old form field/header names to Laravel's normal CSRF validation.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->input('_token')
            ?? $request->input('csrf')
            ?? $request->header('X-CSRF-Token');

        if (is_string($token) && $token !== '') {
            $request->merge(['_token' => $token]);
        }

        return $next($request);
    }
}
