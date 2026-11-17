<?php

/*
 * FILE PURPOSE
 * File   : app/Rules/RealMailDomain.php
 * Feature: Validation
 * Purpose: Validation rule that rejects email addresses whose domain cannot receive mail (typos, fake
 *          domains).
 */

namespace App\Rules;

use App\Support\MailDomainChecker;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects email addresses whose domain cannot receive mail, e.g. admin@astratrack.local
 * or a typo like someone@gmial.con, so notification emails don't silently bounce.
 * Turned off with MAIL_VERIFY_DOMAINS=false (the test suite does this; tests that need it fake the checker).
 */
class RealMailDomain implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! config('mail.verify_domains', true) || ! is_string($value) || ! str_contains($value, '@')) {
            return; // empty values and bad formats are handled by the 'email' rule
        }

        $domain = mb_strtolower(trim(substr($value, strrpos($value, '@') + 1)));
        $checker = app(MailDomainChecker::class);

        if ($domain === '' || $checker->isReserved($domain) || ! $checker->canReceiveMail($domain)) {
            $fail("This email can't receive mail: \"{$domain}\" has no mail server. Check for typos or use a real address.");
        }
    }
}
