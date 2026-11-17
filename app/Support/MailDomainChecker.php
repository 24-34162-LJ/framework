<?php

namespace App\Support;

/**
 * Answers "can this email domain receive mail?" with a DNS lookup.
 * Kept in its own class so tests can swap it for a fake and never touch the network.
 */
class MailDomainChecker
{
    /** Domains reserved for local/testing use that can never receive real mail. */
    private const RESERVED_SUFFIXES = ['.local', '.localhost', '.test', '.invalid', '.example', '.internal', '.lan'];

    public function isReserved(string $domain): bool
    {
        $domain = '.'.mb_strtolower(rtrim($domain, '.'));

        foreach (self::RESERVED_SUFFIXES as $suffix) {
            if (str_ends_with($domain, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** True when the domain has a mail server (MX) record, or an address (A/AAAA) that mail can fall back to. */
    public function canReceiveMail(string $domain): bool
    {
        $domain = rtrim($domain, '.').'.';

        return checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
    }
}
