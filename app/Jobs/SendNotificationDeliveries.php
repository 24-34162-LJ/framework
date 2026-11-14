<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Services\ChannelResult;
use App\Services\EmailIntegration;
use App\Services\SmsIntegration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends pending SMS/email deliveries and records the result (sent / failed) on each row.
 * Dispatched by NotificationService only after the database transaction commits.
 */
class SendNotificationDeliveries implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param  list<int>  $deliveryIds */
    public function __construct(public array $deliveryIds) {}

    public function handle(SmsIntegration $sms, EmailIntegration $email): void
    {
        $deliveries = NotificationDelivery::query()
            ->with('notification.user')
            ->whereIn('delivery_id', $this->deliveryIds)
            ->where('status', NotificationDelivery::STATUS_PENDING)
            ->get();

        foreach ($deliveries as $delivery) {
            try {
                $result = $delivery->channel === NotificationDelivery::CHANNEL_SMS
                    ? $sms->send($delivery->recipient, $delivery->message)
                    : $email->send($delivery->recipient, (string) $delivery->subject, $delivery->message, $this->linkFor($delivery));
            } catch (Throwable $exception) {
                $result = ChannelResult::fail('Send error: '.$exception->getMessage());
            }

            try {
                $delivery->forceFill($result->ok
                    ? ['status' => NotificationDelivery::STATUS_SENT, 'provider_message_id' => $result->providerId, 'sent_at' => now(), 'error' => null]
                    : ['status' => NotificationDelivery::STATUS_FAILED, 'error' => $result->error])->save();
            } catch (Throwable) {
                // Recording the result must never break the action that triggered the notification.
            }
        }
    }

    /** Client users (and company emails) open the client shipment page; admin/staff open the office page. */
    private function linkFor(NotificationDelivery $delivery): ?string
    {
        if (! $delivery->shipment_id) {
            return null;
        }

        $role = $delivery->notification?->user?->role;
        $route = $role !== null && $role !== 'client' ? 'shipments.show' : 'client.shipments.show';

        try {
            return route($route, ['shipmentId' => $delivery->shipment_id]);
        } catch (Throwable) {
            return null;
        }
    }
}
