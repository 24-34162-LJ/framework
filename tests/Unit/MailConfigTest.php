<?php

namespace Tests\Unit;

use Tests\TestCase;

class MailConfigTest extends TestCase
{
    public function test_smtp_has_a_short_timeout_so_a_slow_mail_server_cannot_freeze_a_staff_action(): void
    {
        $this->assertSame(10, (int) config('mail.mailers.smtp.timeout'));
    }

    public function test_env_example_has_a_sender_address(): void
    {
        $this->assertMatchesRegularExpression('/^MAIL_FROM_ADDRESS=\S+@\S+/m', file_get_contents(base_path('.env.example')));
        $this->assertMatchesRegularExpression('/^MAIL_TIMEOUT=10$/m', file_get_contents(base_path('.env.example')));
    }
}
