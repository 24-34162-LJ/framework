<?php

namespace Tests\Unit;

use App\Services\WhatsAppIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppIntegrationTest extends TestCase
{
    private function useMeta(array $overrides = []): void
    {
        config(['services.whatsapp' => array_merge([
            'driver' => 'meta', 'token' => 'secret-token-xyz', 'phone_number_id' => '111',
            'api_version' => 'v25.0', 'base_url' => 'https://wa.test',
            'template' => 'hello_world', 'template_language' => 'en_US', 'template_has_message' => false, 'timeout' => 10,
        ], $overrides)]);
    }

    public function test_off_driver_is_disabled_and_sends_nothing(): void
    {
        config(['services.whatsapp.driver' => 'off']);
        Http::fake();

        $whatsapp = new WhatsAppIntegration;

        $this->assertFalse($whatsapp->enabled());
        $this->assertFalse($whatsapp->send('639171234567', 'Hi')->ok);
        Http::assertNothingSent();
    }

    public function test_log_driver_sends_nothing_over_http(): void
    {
        config(['services.whatsapp.driver' => 'log']);
        Http::fake();

        $this->assertTrue((new WhatsAppIntegration)->send('639171234567', 'Hi')->ok);
        Http::assertNothingSent();
    }

    public function test_meta_success_sends_template_and_returns_message_id(): void
    {
        $this->useMeta();
        Http::fake(['wa.test/*' => Http::response(['messages' => [['id' => 'wamid.ABC']]])]);

        $result = (new WhatsAppIntegration)->send('639171234567', 'AstraTrack: REF-1 is now Delivered');

        $this->assertTrue($result->ok);
        $this->assertSame('wamid.ABC', $result->providerId);
        Http::assertSent(fn ($request) => $request->url() === 'https://wa.test/v25.0/111/messages'
            && $request->hasHeader('Authorization', 'Bearer secret-token-xyz')
            && $request['to'] === '639171234567'
            && $request['type'] === 'template'
            && $request['template']['name'] === 'hello_world'
            && ! isset($request['template']['components']));
    }

    public function test_template_with_message_passes_text_without_sms_prefix(): void
    {
        $this->useMeta(['template' => 'shipment_update', 'template_has_message' => true]);
        Http::fake(['wa.test/*' => Http::response(['messages' => [['id' => 'wamid.X']]])]);

        (new WhatsAppIntegration)->send('639171234567', 'AstraTrack: REF-1 is now Delivered');

        Http::assertSent(fn ($request) => $request['template']['name'] === 'shipment_update'
            && $request['template']['components'][0]['parameters'][0]['text'] === 'REF-1 is now Delivered');
    }

    public function test_missing_token_fails_without_http(): void
    {
        $this->useMeta(['token' => null]);
        Http::fake();

        $result = (new WhatsAppIntegration)->send('639171234567', 'Hi');

        $this->assertFalse($result->ok);
        $this->assertSame('WhatsApp not configured', $result->error);
        Http::assertNothingSent();
    }

    public function test_api_error_fails_without_leaking_token(): void
    {
        $this->useMeta();
        Http::fake(['wa.test/*' => Http::response(['error' => ['message' => 'Recipient phone number not in allowed list secret-token-xyz']], 400)]);

        $result = (new WhatsAppIntegration)->send('639171234567', 'Hi');

        $this->assertFalse($result->ok);
        $this->assertStringStartsWith('WhatsApp HTTP 400: Recipient phone number not in allowed list', $result->error);
        $this->assertStringNotContainsString('secret-token-xyz', $result->error);
    }

    public function test_connection_exception_fails_without_throwing(): void
    {
        $this->useMeta();
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $result = (new WhatsAppIntegration)->send('639171234567', 'Hi');

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('timed out', $result->error);
    }
}
