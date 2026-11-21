<?php

/*
 * FILE PURPOSE
 * File   : app/Rules/StrongPassword.php
 * Feature: Validation
 * Purpose: Password strength rule shared by every form that sets a password (add/edit user, My
 *          Account, client profile, password reset): 10 to 72 characters with an uppercase letter,
 *          a lowercase letter and a number, and not one of the most common passwords.
 */

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class StrongPassword implements ValidationRule
{
    public const MIN = 10;

    public const MAX = 72; // bcrypt ignores anything after 72 bytes

    public const HINT = 'Use 10 to 72 characters with an uppercase letter, a lowercase letter and a number.';

    /** Passwords that pass the character rules but are still guessed first. */
    private const COMMON = ['password1', 'password12', 'password123', 'password1234', 'qwerty1234', 'qwertyuiop1',
        'welcome123', 'welcome1234', 'admin12345', 'administrator1', 'letmein123', 'iloveyou123', 'abcd123456',
        'abc1234567', 'astratrack1', 'astratrack123', 'astracargo1', 'astracargo123'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = (string) $value;
        $length = strlen($value);
        if ($length < self::MIN || $length > self::MAX
            || ! preg_match('/\p{Lu}/u', $value) || ! preg_match('/\p{Ll}/u', $value) || ! preg_match('/\d/', $value)) {
            $fail(self::HINT);

            return;
        }
        if (in_array(mb_strtolower($value), self::COMMON, true)) {
            $fail('That password is too common. Choose something harder to guess.');
        }
    }
}
