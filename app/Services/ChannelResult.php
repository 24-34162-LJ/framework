<?php

namespace App\Services;

/** Result of one SMS or email send: ok + provider id, or a short error. */
final class ChannelResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $providerId = null,
        public readonly ?string $error = null,
    ) {}

    public static function ok(?string $providerId = null): self
    {
        return new self(true, $providerId);
    }

    public static function fail(string $error): self
    {
        return new self(false, null, mb_substr($error, 0, 255));
    }
}
