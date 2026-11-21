<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/Admin/UserManagementController.php
 * Feature: Admin - User management
 * Purpose: Admin page to list, add, edit and deactivate user accounts (never removes the last active
 *          admin).
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveUserRequest;
use App\Models\Client;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function index(): View
    {
        $users = User::query()->with('client')->orderBy('user_id')->get();
        $total = $users->count();
        $oldInput = session()->getOldInput();
        $formState = in_array($oldInput['action'] ?? null, ['add', 'edit'], true)
            ? [
                'mode' => $oldInput['action'],
                'id' => $oldInput['user_id'] ?? null,
                'origName' => $oldInput['full_name'] ?? '',
                'values' => [
                    'full_name' => $oldInput['full_name'] ?? '',
                    'email' => $oldInput['email'] ?? '',
                    'role' => $oldInput['role'] ?? '',
                    'status' => $oldInput['status'] ?? 'active',
                    'client_id' => $oldInput['client_id'] ?? '',
                    'phone' => $oldInput['phone'] ?? '',
                ],
                'errors' => collect(session('errors')?->getBag('default')->messages() ?? [])
                    ->map(fn (array $messages): string => $messages[0] ?? '')
                    ->all(),
            ]
            : null;

        // Latest ID check per client user, for the "ID Check" column (links to the ID record).
        $clientUserIds = $users->where('role', 'client')->pluck('user_id')->all();
        $idChecks = $clientUserIds === [] ? collect() : rescue(fn () => IdentityVerification::query()
            ->whereIn('user_id', $clientUserIds)->orderByDesc('verification_id')
            ->get(['verification_id', 'user_id', 'status', 'created_at'])->unique('user_id')->keyBy('user_id'), collect(), false);

        return view('admin.users.index', [
            'users' => $users,
            'idChecks' => $idChecks,
            'clients' => Client::query()->orderBy('client_name')->get(['client_id', 'client_name']),
            'total' => $total,
            'activeCount' => $users->where('status', 'active')->count(),
            'inactiveCount' => $users->where('status', 'inactive')->count(),
            'adminCount' => $users->where('role', 'admin')->count(),
            'staffCount' => $users->where('role', 'staff')->count(),
            'clientCount' => $users->where('role', 'client')->count(),
            'formState' => $formState,
        ]);
    }

    public function save(SaveUserRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $action = $data['action'];
        $actor = $request->user();
        $target = isset($data['user_id']) ? User::query()->findOrFail($data['user_id']) : null;

        if ($action === 'toggle') {
            abort_if($target->is($actor), 422, 'You cannot change the status of your own account.');

            DB::transaction(function () use ($target, $actor): void {
                $target = User::query()->whereKey($target->user_id)->lockForUpdate()->firstOrFail();
                $this->ensureNotLastActiveAdmin($target);
                $previous = $target->status;
                $target->status = $previous === 'active' ? 'inactive' : 'active';
                $target->save();
                $this->recordActivity($actor, 'Account '.($previous === 'active' ? 'deactivated' : 'activated')." for {$target->full_name} ({$this->userCode($target)})");
            });

            return back()->with('success', 'Account status updated.');
        }

        if ($action === 'delete') {
            abort_if($target->is($actor), 422, 'You cannot delete your own account.');

            DB::transaction(function () use ($target, $actor): void {
                $target = User::query()->whereKey($target->user_id)->lockForUpdate()->firstOrFail();
                $this->ensureNotLastActiveAdmin($target);
                $description = "Account deleted: {$target->full_name} ({$this->userCode($target)}, ".ucfirst($target->role).')';
                $target->delete();
                $this->recordActivity($actor, $description);
            });

            return back()->with('success', 'Account deleted.');
        }

        $isEdit = $action === 'edit';
        $newRole = $data['role'];
        $newStatus = $data['status'];

        if ($isEdit && $target->is($actor) && ($newRole !== 'admin' || $newStatus !== 'active')) {
            throw ValidationException::withMessages(['role' => 'You cannot remove your own Admin access.']);
        }

        try {
            DB::transaction(function () use ($data, $target, $actor, $isEdit, $newRole, $newStatus): void {
                if ($isEdit) {
                    $target = User::query()->whereKey($target->user_id)->lockForUpdate()->firstOrFail();
                    if ($target->role === 'admin' && $target->status === 'active' && ($newRole !== 'admin' || $newStatus !== 'active')) {
                        $this->ensureNotLastActiveAdmin($target);
                    }
                } else {
                    $target = new User;
                }

                $beforeRole = $isEdit ? $target->role : null;
                $beforeStatus = $isEdit ? $target->status : null;
                $target->full_name = trim($data['full_name']);
                $target->email = mb_strtolower(trim($data['email']));
                $target->role = $newRole;
                $target->status = $newStatus;
                $target->client_id = $newRole === 'client' ? $data['client_id'] : null;
                if (array_key_exists('phone', $data)) {
                    $target->phone = PhoneNumber::normalize($data['phone']);
                }
                if (! empty($data['password'])) {
                    $target->password_hash = Hash::make($data['password']);
                }
                $target->save();

                if ($isEdit) {
                    $note = '';
                    if ($beforeRole !== $newRole) {
                        $note .= ", role {$beforeRole} to {$newRole}";
                    }
                    if ($beforeStatus !== $newStatus) {
                        $note .= ", status {$beforeStatus} to {$newStatus}";
                    }
                    if (! empty($data['password'])) {
                        $note .= ', password changed';
                    }
                    $description = "Account details updated for {$target->full_name} ({$this->userCode($target)}){$note}";
                } else {
                    $description = "New {$newRole} account created for {$target->full_name} ({$this->userCode($target)})";
                }

                $this->recordActivity($actor, $description);
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23000') {
                throw ValidationException::withMessages(['email' => 'That email address is already in use.']);
            }

            throw $exception;
        }

        return back()->with('success', $isEdit ? 'User account updated.' : 'Account created.');
    }

    private function ensureNotLastActiveAdmin(User $target): void
    {
        if ($target->role !== 'admin' || $target->status !== 'active') {
            return;
        }

        $activeAdmins = User::query()
            ->where('role', 'admin')
            ->where('status', 'active')
            ->lockForUpdate()
            ->get(['user_id']);

        if ($activeAdmins->count() <= 1) {
            throw ValidationException::withMessages(['role' => 'At least one active Admin account is required.']);
        }
    }

    private function recordActivity(User $actor, string $description): void
    {
        app(\App\Services\ActivityLogger::class)->record($actor, 'User Account', $description);
    }

    private function userCode(User $user): string
    {
        return 'USR-'.str_pad((string) $user->user_id, 4, '0', STR_PAD_LEFT);
    }
}
