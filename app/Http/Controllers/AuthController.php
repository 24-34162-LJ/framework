<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/AuthController.php
 * Feature: Login / Auth
 * Purpose: Login page, sign-in, sign-out, and sending each role (admin / staff / client) to its own
 *          dashboard.
 */

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login', [
            'error' => (string) session('legacy_login_error', ''),
            'old' => (string) old('login', ''),
        ]);
    }

    public function login(Request $request, ActivityLogger $activity): RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $identifier = trim((string) $request->input('login', ''));
        $password = (string) $request->input('password', '');
        if ($identifier === '' || $password === '') {
            return redirect('/login.php')->with('legacy_login_error', 'Enter your email or username and password.')
                ->withInput(['login' => $identifier]);
        }

        // Names are not unique, so several active users can match: sign in the one whose password fits.
        // An exact email match is tried first.
        $user = User::query()
            ->where('status', 'active')
            ->where(fn ($query) => $query->where('email', $identifier)->orWhere('full_name', $identifier))
            ->orderByRaw('CASE WHEN email = ? THEN 0 ELSE 1 END', [$identifier])
            ->orderBy('user_id')
            ->limit(20)
            ->get()
            ->first(fn (User $candidate) => Hash::check($password, $candidate->getAuthPassword()));

        if (! $user) {
            return redirect('/login.php')->with('legacy_login_error', 'Incorrect email/username or password.')
                ->withInput(['login' => $identifier]);
        }

        $activity->record($user, 'User Account', $user->full_name.' logged in', [
            'action' => 'login',
            'role' => $user->role,
        ]);
        // "Remember me" uses Laravel's remember-token cookie, which logs the user back in after the
        // session itself has expired (SESSION_LIFETIME).
        Auth::login($user, $request->filled('remember'));
        $request->session()->regenerate();

        return redirect()->to($this->dashboardPath($user));
    }

    public function dashboard(): RedirectResponse
    {
        $user = Auth::user();
        abort_if(! $user, 403);

        return redirect()->to($this->dashboardPath($user));
    }

    public function logout(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $user = $request->user();
        if ($user) {
            try {
                $activity->record($user, 'User Account', $user->full_name.' logged out', [
                    'action' => 'logout',
                    'role' => $user->role,
                ]);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login.php');
    }

    private function dashboardPath(User $user): string
    {
        return match ($user->role) {
            'admin' => '/account-management/admin.php',
            'staff' => '/account-management/staff.php',
            'client' => '/client-management/client_dashbroad.php',
            default => abort(403),
        };
    }
}
