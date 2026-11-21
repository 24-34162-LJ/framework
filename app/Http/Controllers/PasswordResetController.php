<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/PasswordResetController.php
 * Feature: Login / Auth
 * Purpose: "Forgot password?" for every role: emails a one-time reset link (valid 60 minutes) and
 *          lets the person choose a new password. The answer is the same whether or not the email
 *          has an account, so the page cannot be used to find out who is registered.
 */

namespace App\Http\Controllers;

use App\Mail\NotificationMail;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\ActivityLogger;
use App\Services\PasswordChanger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    private const SENT = 'If that email belongs to an active AstraTrack account, we sent a link to reset the password. Check your inbox (and spam folder). The link works for 60 minutes.';

    public function request(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.password', ['mode' => 'forgot', 'status' => (string) session('status', '')]);
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:120']], [
            'email.required' => 'Enter the email address of your account.',
            'email.email' => 'Enter a valid email address.',
        ]);

        Password::broker()->sendResetLink(['email' => trim($data['email'])], function (User $user, string $token): void {
            if ($user->status !== 'active') {
                return; // inactive accounts cannot sign in, so a reset link would only confuse
            }
            $link = route('password.reset', ['token' => $token, 'email' => $user->email]);
            try {
                Mail::to($user->email)->send(new NotificationMail(
                    'Reset your password',
                    'We received a request to reset the password for your AstraTrack account. The link below works for 60 minutes and can be used once. If you did not ask for this, ignore this email: your password stays the same.',
                    $link,
                    'Reset password',
                ));
            } catch (\Throwable $exception) {
                report($exception);
            }
        });

        // Same answer for unknown emails, inactive accounts and repeated requests.
        return redirect()->route('password.request')->with('status', self::SENT);
    }

    public function edit(Request $request, string $token): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.password', ['mode' => 'reset', 'token' => $token, 'email' => (string) $request->query('email', ''), 'status' => '']);
    }

    public function update(Request $request, PasswordChanger $passwords, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:120'],
            'password' => ['required', 'string', new StrongPassword, 'confirmed'],
        ], ['password.confirmed' => 'The new passwords do not match.']);

        $result = Password::broker()->reset(
            ['email' => trim($data['email']), 'token' => $data['token'], 'password' => $data['password']],
            function (User $user, string $password) use ($passwords, $activity): void {
                $passwords->setPassword($user, $password);
                $activity->record($user, 'User Account', $user->full_name.' reset their password with an email link');
            }
        );

        if ($result !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => 'This reset link is invalid or has expired. Request a new one.']);
        }

        return redirect('/login.php')->with('auth_notice', 'Your password was changed. Sign in with your new password.');
    }
}
