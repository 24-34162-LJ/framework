<?php

/*
 * FILE PURPOSE
 * File   : app/Services/PasswordChanger.php
 * Feature: Account
 * Purpose: Changes a signed-in user's own password (My Account for admin/staff, My Profile for
 *          clients) and sets a new password after a reset link. Other sessions and "Remember me"
 *          logins end because the password hash changes (AuthenticateSession middleware).
 */

namespace App\Services;

use App\Models\User;
use App\Rules\StrongPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordChanger
{
    public function __construct(private ActivityLogger $logger) {}

    /** Validates current + new password (errors go to the "password" bag) and saves it. */
    public function changeOwn(Request $request): void
    {
        $user = $request->user();
        $data = $request->validateWithBag('password', [
            'current_password' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                if (! Hash::check((string) $value, (string) $user->getAuthPassword())) {
                    $fail('Your current password is incorrect.');
                }
            }],
            'password' => ['required', 'string', new StrongPassword, 'confirmed', 'different:current_password'],
        ], [
            'password.confirmed' => 'The new passwords do not match.',
            'password.different' => 'Choose a password different from your current one.',
        ]);

        $this->setPassword($user, $data['password']);
        $request->session()->regenerate();
        $this->logger->record($user, 'Profile Update', 'Own password changed');
    }

    public function setPassword(User $user, string $password): void
    {
        $user->password_hash = Hash::make($password);
        // A new remember token ends "Remember me" logins on other devices.
        $user->setRememberToken(Str::random(60));
        $user->save();
    }
}
