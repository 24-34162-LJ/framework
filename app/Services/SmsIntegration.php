<?php

/*
 * FILE PURPOSE
 * File   : app/Services/SmsIntegration.php
 * Feature: Notifications
 * Purpose: The only code that sends SMS, through the Semaphore SMS gateway (or to the log in
 *          development).
 */

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * SMS integration — the only code in AstraTrack that talks to the Semaphore SMS gateway (semaphore.co).
 *
 * Drivers (config services.semaphore.driver / SMS_DRIVER):
 *  - log:       writes the SMS to the Laravel log instead of sending (free, for development)
 *  - semaphore: sends through the Semaphore API
 *
 * send() never throws: problems come back as a failed ChannelResult so a staff action is never interrupted.
 */
class SmsIntegration
{
    public const PREFIX = 'AstraTrack: ';

    public const MAX_LENGTH = 160;

    public function send(string $phone, string $message): ChannelResult
    {
        $config = (array) config('services.semaphore', []);

        if (($config['driver'] ?? 'log') !== 'semaphore') {
            Log::info('SMS (log driver)', ['to' => $phone, 'message' => $message]);

            return ChannelResult::ok('log');
        }

        $apiKey = (string) ($config['api_key'] ?? '');
        if ($apiKey === '') {
            return ChannelResult::fail('SMS not configured');
        }

        $payload = ['apikey' => $apiKey, 'number' => $phone, 'message' => $message];
        if (filled($config['sender_name'] ?? null)) {
            $payload['sendername'] = $config['sender_name'];
        }

        try {
            $response = Http::asForm()
                ->timeout((int) ($config['timeout'] ?? 10))
                ->post((string) ($config['url'] ?? 'https://api.semaphore.co/api/v4/messages'), $payload);

            $body = $response->json();
            $first = is_array($body) && array_is_list($body) ? ($body[0] ?? null) : null;

            if ($response->successful() && is_array($first) && isset($first['message_id'])) {
                return ChannelResult::ok((string) $first['message_id']);
            }

            $reason = $response->successful()
                ? 'Semaphore rejected the message: '.$this->describe($body)
                : 'Semaphore HTTP '.$response->status();

            return ChannelResult::fail($this->scrub($reason, $apiKey));
        } catch (Throwable $exception) {
            return ChannelResult::fail($this->scrub('SMS send error: '.$exception->getMessage(), $apiKey));
        }
    }

    /** One GSM-safe SMS: app prefix, ASCII only, single line, at most 160 characters. */
    public static function format(string $message): string
    {
        $text = Str::ascii(self::PREFIX.$message);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if (strlen($text) > self::MAX_LENGTH) {
            $text = rtrim(substr($text, 0, self::MAX_LENGTH - 3)).'...';
        }

        return $text;
    }

    private function describe(mixed $body): string
    {
        if (! is_array($body)) {
            return 'unexpected response';
        }

        $messages = [];
        array_walk_recursive($body, function ($value) use (&$messages): void {
            if (is_scalar($value)) {
                $messages[] = (string) $value;
            }
        });

        return $messages ? implode(' ', $messages) : 'unexpected response';
    }

    private function scrub(string $text, string $apiKey): string
    {
        return str_replace($apiKey, '[hidden]', $text);
    }
}
