<?php

namespace App\Support;

/**
 * Philippine mobile numbers for SMS.
 * Accepts 09XXXXXXXXX, +639XXXXXXXXX or 639XXXXXXXXX (spaces, dashes, dots and parentheses ignored)
 * and stores them as 639XXXXXXXXX. Landlines and anything else are "not a mobile".
 */
class PhoneNumber
{
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/[\s\-\.\(\)]/', '', (string) $raw);

        if (! preg_match('/^(?:\+?63|0)(9\d{9})$/', (string) $digits, $match)) {
            return null;
        }

        return '63'.$match[1];
    }

    public static function isMobile(?string $raw): bool
    {
        return self::normalize($raw) !== null;
    }
}
