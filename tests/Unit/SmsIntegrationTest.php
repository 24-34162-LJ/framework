<?php

/*
 * FILE PURPOSE
 * File   : tests/Unit/SmsIntegrationTest.php
 * Feature: Testing - Notifications
 * Purpose: Tests SMS sending through Semaphore.
 */

namespace Tests\Unit;

use App\Services\SmsIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SmsIntegrationTest extends TestCase
{
    private function useSemaphore(?string $key = 'k', ?string $sender = 'ASTRA'): void
    {
        config(['services.semaphore' => [
            'driver' => 'semaphore', 'api_key' => $key, 'sender_name' => $sender,
            'url' => 'https://sms.test/messages', 'timeout' => 10,
        ]]);
    }

    public function test_log_driver_sends_nothing_over_http(): void
    {
        config(['services.semaphore.driver' => 'log']);
        Http::fake();

        $this->assertTrue((new SmsIntegration)->send('639171234567', 'Hi')->ok);
        Http::assertNothingSent();
    }

    public function test_semaphore_success_returns_message_id(): void
    {
        $this->useSemaphore();
        Http::fake(['sms.test/*' => Http::response([['message_id' => 123, 'status' => 'Pending']])]);

        $result = (new SmsIntegration)->send('639171234567', 'Hello');

        $this->assertTrue($result->ok);
        $this->assertSame('123', $result->providerId);
        Http::assertSent(fn ($request) => $request['number'] === '639171234567'
            && $request['apikey'] === 'k'
            && $request['sendername'] === 'ASTRA'
            && $request['message'] === 'Hello');
    }

    public function test_sender_name_is_omitted_when_not_configured(): void
    {
        $this->useSemaphore(sender: null);
        Http::fake(['sms.test/*' => Http::response([['message_id' => 9]])]);

        (new SmsIntegration)->send('639171234567', 'Hello');

        Http::assertSent(fn ($request) => ! isset($request['sendername']));
    }

    public function test_semaphore_error_body_fails_without_leaking_key(): void
    {
        $this->useSemaphore('secret-key-xyz');
        Http::fake(['sms.test/*' => Http::response(['number' => ['The number format is invalid.']], 200)]);

        $result = (new SmsIntegration)->send('639171234567', 'Hello');

        $this->assertFalse($result->ok);
        $this->assertNotEmpty($result->error);
        $this->assertStringNotContainsString('secret-key-xyz', $result->error);
    }

    public function test_semaphore_server_error_fails(): void
    {
        $this->useSemaphore('secret-key-xyz');
        Http::fake(['sms.test/*' => Http::response('oops', 500)]);

        $result = (new SmsIntegration)->send('639171234567', 'Hello');

        $this->assertFalse($result->ok);
        $this->assertNotEmpty($result->error);
        $this->assertStringNotContainsString('secret-key-xyz', $result->error);
    }

    public function test_connection_exception_fails_without_throwing(): void
    {
        $this->useSemaphore('secret-key-xyz');
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $result = (new SmsIntegration)->send('639171234567', 'Hello');

        $this->assertFalse($result->ok);
        $this->assertNotEmpty($result->error);
        $this->assertStringNotContainsString('secret-key-xyz', $result->error);
    }

    public function test_missing_api_key_fails_without_http(): void
    {
        $this->useSemaphore(null);
        Http::fake();

        $result = (new SmsIntegration)->send('639171234567', 'Hi');

        $this->assertFalse($result->ok);
        $this->assertSame('SMS not configured', $result->error);
        Http::assertNothingSent();
    }

    public function test_format_is_ascii_prefixed_and_max_160(): void
    {
        $out = SmsIntegration::format("Document rejected — Señor’s B/L\n".str_repeat('x', 300));

        $this->assertStringStartsWith('AstraTrack: ', $out);
        $this->assertLessThanOrEqual(160, strlen($out));
        $this->assertStringEndsWith('...', $out);
        $this->assertSame($out, Str::ascii($out));
        $this->assertStringNotContainsString("\n", $out);
    }

    public function test_short_message_is_kept_whole(): void
    {
        $this->assertSame('AstraTrack: REF-1 is now Delivered', SmsIntegration::format('REF-1 is now Delivered'));
    }
}
