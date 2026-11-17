<?php

/*
 * FILE PURPOSE
 * File   : app/Services/GeminiClient.php
 * Feature: AI - Gemini
 * Purpose: Sends one file + prompt to Google Gemini and returns its JSON answer. Shared by all Gemini
 *          features.
 */

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Sends one file + prompt to Gemini and returns the JSON answer (same request shape as the prototype). */
class GeminiClient
{
    public function configured(): bool
    {
        $apiKey = trim((string) config('services.gemini.api_key'));

        return $apiKey !== '' && stripos($apiKey, 'PASTE_') === false && stripos($apiKey, 'xxxx') === false;
    }

    /** @return array{data?: array<mixed>, error?: string, status?: int} */
    public function generateJson(string $mimeType, string $bytes, string $prompt): array
    {
        if (! $this->configured()) {
            return ['error' => 'The Gemini API key is missing. Add GEMINI_API_KEY to the Laravel .env file.', 'status' => 500];
        }
        $model = (string) config('services.gemini.model', 'gemini-3.6-flash');

        try {
            $response = Http::connectTimeout(10)->timeout(170)
                ->withOptions($this->tlsOptions())
                ->withHeaders(['x-goog-api-key' => trim((string) config('services.gemini.api_key'))])
                ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', [
                    'contents' => [['parts' => [
                        ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($bytes)]],
                        ['text' => $prompt],
                    ]]],
                    'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0, 'maxOutputTokens' => 8000],
                ]);
        } catch (ConnectionException $e) {
            return ['error' => 'Could not reach the Gemini API: '.$e->getMessage(), 'status' => 502];
        }

        if ($response->status() !== 200) {
            return ['error' => 'Gemini: '.($response->json('error.message') ?? 'API error (HTTP '.$response->status().').'), 'status' => 502];
        }
        $text = '';
        foreach ((array) $response->json('candidates.0.content.parts', []) as $part) {
            if (is_array($part) && isset($part['text'])) {
                $text .= $part['text'];
            }
        }
        if ($text === '') {
            return ['error' => 'Gemini returned no text (the answer may have been blocked or cut off).', 'status' => 502];
        }
        $data = json_decode(trim((string) preg_replace('/```json|```/', '', $text)), true);
        if (! is_array($data)) {
            return ['error' => 'The model did not return valid JSON. Try again.', 'status' => 502];
        }

        return ['data' => $data];
    }

    /** Use the configured CA bundle only when PHP itself has none (keeps certificate checks on). */
    private function tlsOptions(): array
    {
        $bundle = (string) config('services.gemini.ca_bundle', '');
        if ((string) ini_get('curl.cainfo') !== '' || (string) ini_get('openssl.cafile') !== '' || $bundle === '' || ! is_file($bundle)) {
            return [];
        }

        return ['verify' => $bundle];
    }
}
