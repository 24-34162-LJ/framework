<?php

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

        $user = User::query()
            ->where('status', 'active')
            ->where(fn ($query) => $query->where('email', $identifier)->orWhere('full_name', $identifier))
            ->first();

        if (! $user || ! Hash::check($password, $user->getAuthPassword())) {
            return redirect('/login.php')->with('legacy_login_error', 'Incorrect email/username or password.')
                ->withInput(['login' => $identifier]);
        }

        $activity->record($user, 'User Account', $user->full_name.' logged in', [
            'action' => 'login',
            'role' => $user->role,
        ]);
        Auth::login($user);
        $request->session()->regenerate();

        $response = redirect()->to($this->dashboardPath($user));
        if ($request->filled('remember')) {
            $response->withCookie(cookie(
                config('session.cookie'),
                $request->session()->getId(),
                60 * 24 * 30,
                config('session.path'),
                config('session.domain'),
                (bool) config('session.secure'),
                (bool) config('session.http_only'),
                false,
                config('session.same_site'),
            ));
        }

        return $response;
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
