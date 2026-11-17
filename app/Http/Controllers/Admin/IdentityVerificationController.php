<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IdentityVerification;
use App\Services\ActivityLogger;
use App\Services\Identity\IdentityVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Admin review queue for client IDs. Only admins can see ID images; every view is logged. */
class IdentityVerificationController extends Controller
{
    private const FILTERS = ['needs_review', 'verified', 'rejected', 'all'];

    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), self::FILTERS, true) ? (string) $request->query('status') : 'needs_review';
        $verifications = IdentityVerification::query()->with(['user', 'client'])
            ->when($status === 'needs_review', fn ($query) => $query->needsAdmin())
            ->when(! in_array($status, ['needs_review', 'all'], true), fn ($query) => $query->where('status', $status))
            ->orderByDesc('verification_id')->paginate(20)->withQueryString();
        $counts = IdentityVerification::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $counts['needs_review'] = IdentityVerification::query()->needsAdmin()->count();

        return view('admin.identity.index', compact('verifications', 'status', 'counts'));
    }

    public function show(int $verificationId): View
    {
        $verification = IdentityVerification::query()->with(['user', 'client', 'reviewer'])->findOrFail($verificationId);

        return view('admin.identity.show', ['verification' => $verification]);
    }

    public function image(Request $request, int $verificationId, IdentityVerificationService $service, ActivityLogger $activity): Response
    {
        $verification = IdentityVerification::query()->findOrFail($verificationId);
        $bytes = $service->imageBytes($verification);
        abort_if($bytes === null, 404, 'The ID image has been deleted after the retention period.');

        $activity->record($request->user(), 'ID Verification', 'Viewed ID image #'.$verification->verification_id, [
            'verification_id' => $verification->verification_id,
            'client_id' => $verification->client_id,
        ]);

        return response($bytes, 200, [
            'Content-Type' => $verification->file_mime ?: 'application/octet-stream',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }

    public function decide(Request $request, int $verificationId, IdentityVerificationService $service): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in([IdentityVerification::STATUS_VERIFIED, IdentityVerification::STATUS_REJECTED])],
            'review_note' => ['nullable', 'string', 'max:255', 'required_if:decision,rejected'],
        ], ['review_note.required_if' => 'Tell the client why the ID was not accepted.']);

        $verification = IdentityVerification::query()->findOrFail($verificationId);
        $service->decide($verification, $request->user(), $data['decision'], $data['review_note'] ?? null);

        return redirect()->route('admin.identity.index')
            ->with('success', 'ID #'.$verification->verification_id.' '.($data['decision'] === IdentityVerification::STATUS_VERIFIED ? 'approved.' : 'rejected.'));
    }
}
