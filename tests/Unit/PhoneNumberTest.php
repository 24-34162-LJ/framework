<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_normalizes_ph_mobile_formats(): void
    {
        $this->assertSame('639171234567', PhoneNumber::normalize('09171234567'));
        $this->assertSame('639171234567', PhoneNumber::normalize('+63 917-123-4567'));
        $this->assertSame('639171234567', PhoneNumber::normalize('0917 123 4567'));
        $this->assertSame('639171234567', PhoneNumber::normalize('639171234567'));
        $this->assertTrue(PhoneNumber::isMobile('0917.123.4567'));
    }

    public function test_rejects_landlines_and_junk(): void
    {
        foreach (['(02) 8123-4567', '028123456', '', null, '0917123456', 'call me', '0817123456789'] as $raw) {
            $this->assertNull(PhoneNumber::normalize($raw), (string) $raw);
            $this->assertFalse(PhoneNumber::isMobile($raw), (string) $raw);
        }
    }
}
