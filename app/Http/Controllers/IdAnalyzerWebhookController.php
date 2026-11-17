<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/IdAnalyzerWebhookController.php
 * Feature: ID Recognition
 * Purpose: Receives the webhook from ID Analyzer when a live DocuPass check finishes; checks the
 *          signature, then saves the result.
 */

namespace App\Http\Controllers;

use App\Services\Identity\DocupassService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives ID Analyzer webhooks (set the URL under Profile > General in the ID Analyzer portal).
 * Every call must carry a valid X-IDA-Signature (HMAC-SHA256 of "timestamp.body" with IDANALYZER_WEBHOOK_SECRET),
 * sent within 5 minutes, or it is ignored. Docs: https://developer.idanalyzer.com/help/webhook-2
 */
class IdAnalyzerWebhookController extends Controller
{
    public function __invoke(Request $request, DocupassService $docupass): JsonResponse
    {
        $secret = (string) config('services.idanalyzer.webhook_secret');
        if ($secret === '' || ! $this->signatureValid($request, $secret)) {
            return response()->json(['ok' => false], 401);
        }

        $payload = $request->json()->all();
        if (($payload['event'] ?? null) !== 'docupass_conclusive') {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $verification = $docupass->findFor($payload);
        if ($verification) {
            $docupass->complete($verification, $payload);
        }

        return response()->json(['ok' => true]);
    }

    private function signatureValid(Request $request, string $secret): bool
    {
        $timestamp = (string) $request->header('X-IDA-Timestamp', '');
        $signature = (string) $request->header('X-IDA-Signature', '');
        if ($timestamp === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        return hash_equals('v1='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), $signature);
    }
}
