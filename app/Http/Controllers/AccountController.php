<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/AccountController.php
 * Feature: Account
 * Purpose: "My Account" page for admins and staff: see their own account, edit name/phone/SMS,
 *          change their password, and see their recent activity. Opened from the user's name
 *          in the sidebar and in the top-right menu.
 */

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Shipment;
use App\Services\ActivityLogger;
use App\Services\PasswordChanger;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $activity = ActivityLog::query()->where('performed_by', $user->user_id)
            ->orderByDesc('created_at')->orderByDesc('activity_id')->limit(10)->get();
        $openShipments = $user->role === 'staff'
            ? Shipment::query()->where('assigned_staff_id', $user->user_id)->whereNull('archived_at')
                ->whereHas('stage', fn ($stage) => $stage->where('is_closed', false))->count()
            : null;

        return view('account.show', compact('user', 'activity', 'openShipments'));
    }

    public function updateDetails(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $data = $request->validateWithBag('details', [
            'full_name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail): void {
                if (filled($value) && ! PhoneNumber::isMobile((string) $value)) {
                    $fail('Enter a Philippine mobile number like 09171234567.');
                }
            }],
            'sms_enabled' => ['nullable', 'boolean'],
        ], ['full_name.min' => 'Enter a full name with at least 2 characters.']);

        $user = $request->user();
        $user->full_name = trim((string) preg_replace('/\s+/u', ' ', $data['full_name']));
        $user->phone = PhoneNumber::normalize($data['phone'] ?? null);
        $user->sms_enabled = $request->boolean('sms_enabled') && $user->phone !== null;
        $user->save();
        $logger->record($user, 'Profile Update', 'Own account details updated (SMS '.($user->sms_enabled ? 'on' : 'off').')');

        return redirect()->route('account')->with('account_success', 'Account details saved.');
    }

    public function updatePassword(Request $request, PasswordChanger $passwords): RedirectResponse
    {
        $passwords->changeOwn($request);

        return redirect()->route('account')->withFragment('password')
            ->with('password_success', 'Password changed. Any other devices signed in to your account were signed out.');
    }
}
