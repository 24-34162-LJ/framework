<?php

/*
 * FILE PURPOSE
 * File   : tests/Unit/EmailIntegrationTest.php
 * Feature: Testing - Notifications
 * Purpose: Tests sending notification emails.
 */

namespace Tests\Unit;

use App\Mail\NotificationMail;
use App\Services\EmailIntegration;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailIntegrationTest extends TestCase
{
    public function test_sends_notification_mail(): void
    {
        Mail::fake();

        $result = (new EmailIntegration)->send('client@example.com', 'Shipment status updated', 'REF-1 is now Delivered', 'https://app.test/shipments/1');

        $this->assertTrue($result->ok);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo('client@example.com')
            && $mail->subjectLine === 'Shipment status updated'
            && $mail->body === 'REF-1 is now Delivered'
            && $mail->link === 'https://app.test/shipments/1');
    }

    public function test_mail_renders_subject_message_and_link(): void
    {
        $mail = new NotificationMail('Document requested', 'REF-1: please upload the invoice.', 'https://app.test/shipments/1');

        $mail->assertHasSubject('AstraTrack: Document requested');
        $mail->assertSeeInHtml('REF-1: please upload the invoice.');
        $mail->assertSeeInHtml('https://app.test/shipments/1');
    }

    public function test_mailer_exception_returns_failure(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $result = (new EmailIntegration)->send('client@example.com', 'S', 'M');

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('SMTP down', $result->error);
    }

    public function test_invalid_address_fails(): void
    {
        Mail::fake();

        $this->assertFalse((new EmailIntegration)->send('not-an-email', 'S', 'M')->ok);
        Mail::assertNothingSent();
    }
}
