<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\Identity\IdentityVerificationService;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request, IdentityVerificationService $identityService): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->status !== 'active') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        $user->load('client');
        $client = $user->client;

        $accountFields = [
            'Full name' => $user->full_name,
            'Login email' => $user->email,
            'Account status' => ucfirst((string) $user->status),
            'Member since' => $user->created_at ? Carbon::parse($user->created_at)->format('M d, Y') : null,
        ];
        $companyFields = [
            'Company name' => $client?->client_name,
            'Company contact' => $client?->contact_person,
            'Company email' => $client?->email,
            'Phone' => $client?->phone,
            'Address' => $client?->address,
            'Company status' => $client?->status ? ucfirst((string) $client->status) : null,
        ];

        $contact = ['phone' => $user->phone, 'sms_enabled' => (bool) ($user->sms_enabled ?? true)];

        // ID verification panel; skipped (not fatal) if its table is missing, e.g. before migrating.
        $identity = rescue(fn () => $identityService->panelData($user), null, false);

        return view('client.profile.show', compact('accountFields', 'companyFields', 'contact', 'identity'));
    }

    /** Save the client's own mobile number and SMS on/off choice (used for SMS notifications). */
    public function updateContact(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail): void {
                if (filled($value) && ! PhoneNumber::isMobile((string) $value)) {
                    $fail('Enter a Philippine mobile number like 09171234567.');
                }
            }],
            'sms_enabled' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $user->phone = PhoneNumber::normalize($data['phone'] ?? null);
        $user->sms_enabled = $request->boolean('sms_enabled');
        $user->save();

        $activity->record($user, 'Profile Update', 'Contact settings updated (SMS '.($user->sms_enabled ? 'on' : 'off').')');

        return back()->with('success', 'Contact settings saved.');
    }
}
