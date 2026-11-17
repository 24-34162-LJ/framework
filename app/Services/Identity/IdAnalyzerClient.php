<?php

/*
 * FILE PURPOSE
 * File   : app/Services/Identity/IdAnalyzerClient.php
 * Feature: ID Recognition
 * Purpose: Small HTTP client for the ID Analyzer API (sends the requests, returns data or an error).
 */

namespace App\Services\Identity;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the ID Analyzer v2 API (https://developer.idanalyzer.com/help).
 * Every call returns ['data' => ...] or ['error' => '...'] instead of throwing.
 */
class IdAnalyzerClient
{
    public function configured(): bool
    {
        return trim((string) config('services.idanalyzer.api_key')) !== '';
    }

    /** @return array{data?: array<mixed>, error?: string} */
    public function post(string $path, array $body, int $timeout = 120): array
    {
        return $this->send(fn (PendingRequest $http) => $http->timeout($timeout)->post($this->url($path), $body));
    }

    /** @return array{data?: array<mixed>, error?: string} */
    public function get(string $path, array $query = []): array
    {
        return $this->send(fn (PendingRequest $http) => $http->timeout(60)->get($this->url($path), $query));
    }

    /** Raw bytes of an output image from the image vault, or null. */
    public function image(string $token): ?string
    {
        if (! $this->configured() || preg_match('/^[A-Za-z0-9_-]{8,200}$/', $token) !== 1) {
            return null;
        }
        try {
            $response = $this->http()->timeout(60)->get($this->url('/imagevault/'.$token));
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() && $response->body() !== '' ? $response->body() : null;
    }

    private function send(callable $call): array
    {
        if (! $this->configured()) {
            return ['error' => 'The ID Analyzer API key is missing. Add IDANALYZER_API_KEY to the Laravel .env file.'];
        }
        try {
            $response = $call($this->http());
        } catch (ConnectionException $e) {
            return ['error' => 'Could not reach ID Analyzer: '.$e->getMessage()];
        }

        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ($body['success'] ?? true) === false || isset($body['error'])) {
            $message = is_array($body) ? ($body['error']['message'] ?? null) : null;

            return ['error' => 'ID Analyzer: '.($message ?? 'API error (HTTP '.$response->status().').')];
        }

        return ['data' => $body];
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout(10)->withOptions($this->tlsOptions())
            ->withHeaders(['X-API-KEY' => trim((string) config('services.idanalyzer.api_key')), 'Accept' => 'application/json']);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.idanalyzer.base_url', 'https://api2.idanalyzer.com'), '/').'/'.ltrim($path, '/');
    }

    /** Same CA-bundle fallback as GeminiClient (XAMPP's PHP often has no CA file configured). */
    private function tlsOptions(): array
    {
        $bundle = (string) config('services.gemini.ca_bundle', '');
        if ((string) ini_get('curl.cainfo') !== '' || (string) ini_get('openssl.cafile') !== '' || $bundle === '' || ! is_file($bundle)) {
            return [];
        }

        return ['verify' => $bundle];
    }
}
