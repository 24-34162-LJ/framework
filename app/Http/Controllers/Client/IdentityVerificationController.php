<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\IdentityVerification;
use App\Services\Identity\IdentityVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Client side of ID recognition (shown on My Profile): consent, upload a photo of a government ID, see the result. */
class IdentityVerificationController extends Controller
{
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    private const MIN_WIDTH = 500;

    /** The ID panel lives on My Profile; keep the old address working. */
    public function show(): RedirectResponse
    {
        return redirect()->to(self::panelUrl());
    }

    public static function panelUrl(): string
    {
        return route('client.profile').'#id-verification';
    }

    public function store(Request $request, IdentityVerificationService $service): RedirectResponse
    {
        $request->validate([
            'id_image' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
            'consent' => ['accepted'],
        ], [
            'id_image.required' => 'Choose a photo of the front of your ID.',
            'id_image.max' => 'The photo must be 5 MB or smaller.',
            'id_image.mimes' => 'Upload a JPG, PNG or WEBP photo of your ID.',
            'consent.accepted' => 'Please agree to the processing of your ID before uploading.',
        ]);

        $user = $request->user();
        $file = $request->file('id_image');

        // Trust the file contents, not the extension.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $size = @getimagesize($file->getRealPath());
        if (! in_array($mime, self::ALLOWED_MIME, true) || $size === false) {
            throw ValidationException::withMessages(['id_image' => 'The file is not a readable image.']);
        }
        if ($size[0] < self::MIN_WIDTH && $size[1] < self::MIN_WIDTH) {
            throw ValidationException::withMessages(['id_image' => 'The photo is too small. Take it closer so the ID fills the picture.']);
        }

        $inProgress = IdentityVerification::query()->where('user_id', $user->user_id)
            ->where('status', IdentityVerification::STATUS_PROCESSING)
            ->where('created_at', '>=', now()->subMinutes(IdentityVerification::STALE_PROCESSING_MINUTES))->exists();
        if ($inProgress) {
            throw ValidationException::withMessages(['id_image' => 'Your last ID is still being read. Please wait for the result.']);
        }

        $max = (int) config('services.identity.max_attempts_per_day', 5);
        if ($service->attemptsToday($user) >= $max) {
            throw ValidationException::withMessages(['id_image' => "You have reached {$max} uploads today. Please try again tomorrow."]);
        }

        set_time_limit(180); // only matters with QUEUE_CONNECTION=sync, where Gemini is called inline (~30 s)
        $verification = $service->submit($user, $file, $request->ip());

        return redirect()->to(self::panelUrl())->with('identity_result', $verification->status);
    }

    /** Polled by the page while the ID is being read in the background. */
    public function status(Request $request): JsonResponse
    {
        $latest = IdentityVerification::query()->where('user_id', $request->user()->user_id)
            ->orderByDesc('verification_id')->first();

        return response()->json([
            'status' => $latest?->status,
            'processing' => $latest?->status === IdentityVerification::STATUS_PROCESSING && ! $latest->isStaleProcessing(),
        ]);
    }
}
