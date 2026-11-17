<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/NotificationController.php
 * Feature: Notifications
 * Purpose: Bell menu / notifications page: list notifications and mark them as read.
 */

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $filter = $request->query('filter') === 'unread' ? 'unread' : 'all';
        $query = Notification::query()->with('shipment')->where('user_id', $request->user()->user_id);
        if ($filter === 'unread') {
            $query->whereNull('read_at');
        }
        $items = $query->orderByDesc('created_at')->orderByDesc('notification_id')->paginate(20)->withQueryString();
        $unreadCount = $request->user()->notifications()->whereNull('read_at')->count();

        return view('notifications.index', compact('filter', 'items', 'unreadCount'));
    }

    public function markRead(Request $request, int $notificationId): JsonResponse
    {
        $notification = Notification::query()->where('user_id', $request->user()->user_id)->findOrFail($notificationId);
        if ($notification->read_at === null) {
            $notification->read_at = now();
            $notification->save();
        }
        $unreadCount = $request->user()->notifications()->whereNull('read_at')->count();

        return response()->json(['ok' => true, 'unread' => $unreadCount]);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return redirect()->route('notifications.index', ['filter' => 'all'])->with('success', 'All notifications marked as read.');
    }

    public function legacyAction(Request $request): JsonResponse
    {
        $action = (string) $request->input('action', 'read');
        $notifications = $request->user()->notifications();

        if ($action === 'all') {
            $notifications->whereNull('read_at')->update(['read_at' => now()]);
        } elseif (in_array($action, ['read', 'unread'], true)) {
            $notificationId = $request->integer('notification_id');
            if ($notificationId < 1) {
                return response()->json(['error' => 'Notification not found.'], 422);
            }

            $notification = $notifications->where('notification_id', $notificationId)->first();
            if (! $notification) {
                return response()->json(['error' => 'Notification not found.'], 404);
            }

            $notification->read_at = $action === 'read' ? now() : null;
            $notification->save();
        } else {
            return response()->json(['error' => 'Invalid notification action.'], 422);
        }

        return response()->json([
            'ok' => true,
            'unread' => $request->user()->notifications()->whereNull('read_at')->count(),
        ])->header('Cache-Control', 'no-store');
    }
}
