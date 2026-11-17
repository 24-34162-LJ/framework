<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WhatsApp integration — the only code in AstraTrack that talks to Meta's WhatsApp Cloud API.
 *
 * Drivers (config services.whatsapp.driver / WHATSAPP_DRIVER):
 *  - off:  WhatsApp is not used; client mobile notifications go by SMS only
 *  - log:  writes the message to the Laravel log instead of sending (free, for development)
 *  - meta: sends an approved message template through the Cloud API
 *
 * Business-initiated WhatsApp messages must use a template approved in WhatsApp Manager, so the
 * notification text is passed as the template's {{1}} body variable (when template_has_message is on).
 *
 * send() never throws: problems come back as a failed ChannelResult so SMS can take over.
 */
class WhatsAppIntegration
{
    public function enabled(): bool
    {
        return in_array($this->config()['driver'] ?? 'off', ['log', 'meta'], true);
    }

    /** @param  string  $phone  normalized 639XXXXXXXXX (see App\Support\PhoneNumber) */
    public function send(string $phone, string $message): ChannelResult
    {
        $config = $this->config();
        $driver = $config['driver'] ?? 'off';

        if ($driver === 'log') {
            Log::info('WhatsApp (log driver)', ['to' => $phone, 'message' => $message]);

            return ChannelResult::ok('log');
        }
        if ($driver !== 'meta') {
            return ChannelResult::fail('WhatsApp is off');
        }

        $token = (string) ($config['token'] ?? '');
        $phoneNumberId = (string) ($config['phone_number_id'] ?? '');
        if ($token === '' || $phoneNumberId === '') {
            return ChannelResult::fail('WhatsApp not configured');
        }

        $url = rtrim((string) ($config['base_url'] ?? 'https://graph.facebook.com'), '/')
            .'/'.($config['api_version'] ?? 'v25.0').'/'.$phoneNumberId.'/messages';

        try {
            $response = Http::withToken($token)
                ->withOptions($this->tlsOptions())
                ->acceptJson()
                ->timeout((int) ($config['timeout'] ?? 10))
                ->post($url, $this->payload($phone, $message, $config));

            $body = $response->json();
            $messageId = is_array($body) ? ($body['messages'][0]['id'] ?? null) : null;

            if ($response->successful() && is_string($messageId) && $messageId !== '') {
                return ChannelResult::ok(mb_substr($messageId, 0, 64));
            }

            $detail = is_array($body) ? ($body['error']['message'] ?? null) : null;
            $reason = 'WhatsApp HTTP '.$response->status().(is_string($detail) && $detail !== '' ? ': '.$detail : '');

            return ChannelResult::fail($this->scrub($reason, $token));
        } catch (Throwable $exception) {
            return ChannelResult::fail($this->scrub('WhatsApp send error: '.$exception->getMessage(), $token));
        }
    }

    private function payload(string $phone, string $message, array $config): array
    {
        $template = [
            'name' => (string) ($config['template'] ?? 'hello_world'),
            'language' => ['code' => (string) ($config['template_language'] ?? 'en_US')],
        ];

        if ($config['template_has_message'] ?? false) {
            $template['components'] = [[
                'type' => 'body',
                'parameters' => [['type' => 'text', 'text' => $this->variableText($message)]],
            ]];
        }

        return [
            'messaging_product' => 'whatsapp',
            'to' => $phone,
            'type' => 'template',
            'template' => $template,
        ];
    }

    /** Template variables may not contain new lines, tabs or runs of spaces; drop the SMS "AstraTrack: " prefix. */
    private function variableText(string $message): string
    {
        if (str_starts_with($message, SmsIntegration::PREFIX)) {
            $message = substr($message, strlen(SmsIntegration::PREFIX));
        }

        return trim((string) preg_replace('/\s+/', ' ', $message));
    }

    /** Same CA bundle fallback as GeminiClient: Windows PHP without curl.cainfo fails HTTPS with cURL error 60. */
    private function tlsOptions(): array
    {
        $bundle = (string) config('services.gemini.ca_bundle', '');
        if ((string) ini_get('curl.cainfo') !== '' || (string) ini_get('openssl.cafile') !== '' || $bundle === '' || ! is_file($bundle)) {
            return [];
        }

        return ['verify' => $bundle];
    }

    private function config(): array
    {
        return (array) config('services.whatsapp', []);
    }

    private function scrub(string $text, string $token): string
    {
        return $token === '' ? $text : str_replace($token, '[hidden]', $text);
    }
}
