<?php

namespace App\Services;

use App\Jobs\SendNotificationDeliveries;
use App\Models\Client;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\Shipment;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Central notification hub ("Record Activity and Notify").
 *
 * 1. Creates the in-app notifications (bell menu) for the right users.
 * 2. Looks up the SMS/email rule for the event (config/notifications.php).
 * 3. Records one notification_deliveries row per SMS/email to send.
 * 4. After the database transaction commits, hands those rows to SendNotificationDeliveries,
 *    which calls SmsIntegration (Semaphore) and EmailIntegration (SMTP).
 */
class NotificationService
{
    public function forShipment(Shipment $shipment, ?User $actor, string $eventType, string $title, string $message, ?string $key = null): void
    {
        $this->forShipmentRoles($shipment, $actor, ['admin', 'staff', 'client'], $eventType, $title, $message, $key);
    }

    /** Notify only some audiences of a shipment: admins, its assigned staff, and/or the client's users. */
    public function forShipmentRoles(Shipment $shipment, ?User $actor, array $roles, string $eventType, string $title, string $message, ?string $key = null): void
    {
        $recipients = User::query()
            ->where('status', 'active')
            ->where(function ($query) use ($shipment, $roles): void {
                $query->whereRaw('1 = 0')
                    ->when(in_array('admin', $roles, true), fn ($q) => $q->orWhere('role', 'admin'))
                    ->when(in_array('staff', $roles, true) && $shipment->assigned_staff_id, fn ($q) => $q->orWhere(fn ($staff) => $staff->where('role', 'staff')->where('user_id', $shipment->assigned_staff_id)))
                    ->when(in_array('client', $roles, true), fn ($q) => $q->orWhere(fn ($client) => $client->where('role', 'client')->where('client_id', $shipment->client_id)));
            })
            ->when($actor, fn ($query) => $query->where('user_id', '<>', $actor->user_id))
            ->get();

        $sent = [];
        foreach ($recipients as $recipient) {
            $sent[] = [$recipient, $this->createInApp((int) $recipient->user_id, (int) $shipment->shipment_id, $eventType, $title, $message)];
        }

        $this->deliverExternally($sent, $eventType, $key, $title, $message, (int) $shipment->shipment_id, (int) $shipment->client_id);
    }

    /** Notifications not tied to a shipment yet (e.g. a client's new shipment request). */
    public function forUsers(iterable $userIds, ?User $actor, string $eventType, string $title, string $message, ?int $shipmentId = null, ?string $key = null, ?int $clientId = null): void
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->unique()
            ->reject(fn (int $id) => $actor && $id === (int) $actor->user_id)
            ->values();
        $users = User::query()->whereIn('user_id', $ids->all())->get()->keyBy(fn (User $user) => (int) $user->user_id);

        $sent = [];
        foreach ($ids as $userId) {
            $notification = $this->createInApp($userId, $shipmentId, $eventType, $title, $message);
            if ($users->has($userId)) {
                $sent[] = [$users->get($userId), $notification];
            }
        }

        $this->deliverExternally($sent, $eventType, $key, $title, $message, $shipmentId, $clientId);
    }

    /** @return list<int> active admin and staff user ids */
    public function officeUserIds(): array
    {
        return User::query()->where('status', 'active')->whereIn('role', ['admin', 'staff'])->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> active users of a client account */
    public function clientUserIds(int $clientId): array
    {
        return User::query()->where('status', 'active')->where('role', 'client')->where('client_id', $clientId)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    private function createInApp(int $userId, ?int $shipmentId, string $eventType, string $title, string $message): Notification
    {
        return Notification::query()->create([
            'user_id' => $userId,
            'shipment_id' => $shipmentId,
            'event_type' => mb_substr($eventType, 0, 40),
            'title' => mb_substr($title, 0, 120),
            'message' => mb_substr($message, 0, 255),
        ]);
    }

    /** @return array{email: bool, email_roles?: list<string>, sms: ?string} */
    private function ruleFor(string $eventType, ?string $key): array
    {
        $rules = (array) config('notifications.rules', []);

        return ($key !== null ? ($rules[$key] ?? null) : null)
            ?? $rules[$eventType]
            ?? (array) config('notifications.default', ['email' => true, 'sms' => null]);
    }

    /**
     * @param  list<array{0: User, 1: Notification}>  $sent  each notified user with their in-app notification
     */
    private function deliverExternally(array $sent, string $eventType, ?string $key, string $title, string $message, ?int $shipmentId, ?int $clientId): void
    {
        if ($sent === []) {
            return;
        }

        $rule = $this->ruleFor($eventType, $key);
        $clientId ??= collect($sent)->map(fn (array $pair) => $pair[0]->client_id)->filter()->first();
        $client = $clientId ? Client::query()->find($clientId) : null;

        $deliveries = [
            ...$this->emailDeliveries($sent, $rule, $client, $title, $message, $shipmentId),
            ...$this->smsDeliveries($sent, $rule, $client, $message, $shipmentId),
        ];

        $this->queueDeliveries(
            collect($deliveries)
                ->filter(fn (NotificationDelivery $delivery) => $delivery->status === NotificationDelivery::STATUS_PENDING)
                ->map(fn (NotificationDelivery $delivery) => (int) $delivery->delivery_id)
                ->values()
                ->all()
        );
    }

    /** @return list<NotificationDelivery> */
    private function emailDeliveries(array $sent, array $rule, ?Client $client, string $title, string $message, ?int $shipmentId): array
    {
        if (! ($rule['email'] ?? false)) {
            return [];
        }

        $roles = $rule['email_roles'] ?? null;
        $companyEmail = ($rule['sms'] ?? null) === 'company' && $client && filled($client->email) ? (string) $client->email : null;
        $deliveries = [];
        $seen = [];

        foreach ($sent as [$user, $notification]) {
            if ($roles !== null && ! in_array($user->role, $roles, true)) {
                continue;
            }
            if ($companyEmail !== null && $user->role === 'client') {
                continue; // company messages go once to the company email below
            }
            $address = mb_strtolower(trim((string) $user->email));
            if ($address === '' || isset($seen[$address])) {
                continue;
            }
            $seen[$address] = true;
            $deliveries[] = $this->record(NotificationDelivery::CHANNEL_EMAIL, (string) $user->email, $title, $message, $notification->notification_id, $shipmentId);
        }

        $hasClientRecipient = collect($sent)->contains(fn (array $pair) => $pair[0]->role === 'client');
        if ($companyEmail !== null && $hasClientRecipient && ! isset($seen[mb_strtolower($companyEmail)])) {
            $deliveries[] = $this->record(NotificationDelivery::CHANNEL_EMAIL, $companyEmail, $title, $message, null, $shipmentId);
        }

        return $deliveries;
    }

    /** @return list<NotificationDelivery> */
    private function smsDeliveries(array $sent, array $rule, ?Client $client, string $message, ?int $shipmentId): array
    {
        $target = $rule['sms'] ?? null;
        if (! in_array($target, ['user', 'company'], true)) {
            return [];
        }

        /** @var Collection<int, array{0: User, 1: Notification}> $clients SMS goes to client users only, never admin/staff */
        $clients = collect($sent)->filter(fn (array $pair) => $pair[0]->role === 'client' && (bool) ($pair[0]->sms_enabled ?? true));
        if ($clients->isEmpty()) {
            return [];
        }

        $companyMobile = PhoneNumber::normalize($client?->phone);
        $text = SmsIntegration::format($message);

        if ($target === 'company' && $companyMobile !== null) {
            return [$this->record(NotificationDelivery::CHANNEL_SMS, $companyMobile, null, $text, null, $shipmentId)];
        }

        $deliveries = [];
        $seen = [];
        foreach ($clients as [$user, $notification]) {
            $phone = PhoneNumber::normalize($user->phone)
                ?? ($target === 'user' ? $companyMobile : null);

            if ($phone === null) {
                $deliveries[] = $this->record(NotificationDelivery::CHANNEL_SMS, '', null, $text, $notification->notification_id, $shipmentId,
                    NotificationDelivery::STATUS_SKIPPED, 'No mobile number');

                continue;
            }
            if (isset($seen[$phone])) {
                continue;
            }
            $seen[$phone] = true;
            $deliveries[] = $this->record(NotificationDelivery::CHANNEL_SMS, $phone, null, $text, $notification->notification_id, $shipmentId);
        }

        return $deliveries;
    }

    private function record(string $channel, string $recipient, ?string $subject, string $message, ?int $notificationId, ?int $shipmentId,
        string $status = NotificationDelivery::STATUS_PENDING, ?string $error = null): NotificationDelivery
    {
        return NotificationDelivery::query()->create([
            'notification_id' => $notificationId,
            'shipment_id' => $shipmentId,
            'channel' => $channel,
            'recipient' => mb_substr($recipient, 0, 120),
            'subject' => $subject !== null ? mb_substr($subject, 0, 150) : null,
            'message' => mb_substr($message, 0, 500),
            'status' => $status,
            'error' => $error,
        ]);
    }

    /** @param  list<int>  $deliveryIds */
    protected function queueDeliveries(array $deliveryIds): void
    {
        if ($deliveryIds === []) {
            return;
        }

        // Runs right away when there is no open transaction; dropped if the transaction rolls back.
        DB::afterCommit(function () use ($deliveryIds): void {
            try {
                SendNotificationDeliveries::dispatch($deliveryIds);
            } catch (Throwable $exception) {
                Log::warning('Could not send SMS/email notifications', ['error' => $exception->getMessage()]);
            }
        });
    }
}
