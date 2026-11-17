<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Services\ChannelResult;
use App\Services\EmailIntegration;
use App\Services\SmsIntegration;
use App\Services\WhatsAppIntegration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends pending SMS/email deliveries and records the result (sent / failed) on each row.
 * Client mobile ("sms") rows go by WhatsApp first when it is enabled, with SMS as the backup.
 * Dispatched by NotificationService only after the database transaction commits.
 */
class SendNotificationDeliveries implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param  list<int>  $deliveryIds */
    public function __construct(public array $deliveryIds) {}

    public function handle(SmsIntegration $sms, EmailIntegration $email, WhatsAppIntegration $whatsapp): void
    {
        $deliveries = NotificationDelivery::query()
            ->with('notification.user')
            ->whereIn('delivery_id', $this->deliveryIds)
            ->where('status', NotificationDelivery::STATUS_PENDING)
            ->get();

        foreach ($deliveries as $delivery) {
            $channel = $delivery->channel;

            try {
                if ($delivery->channel === NotificationDelivery::CHANNEL_SMS) {
                    [$result, $channel] = $this->sendToMobile($delivery, $whatsapp, $sms);
                } else {
                    $result = $email->send($delivery->recipient, (string) $delivery->subject, $delivery->message, $this->linkFor($delivery));
                }
            } catch (Throwable $exception) {
                $result = ChannelResult::fail('Send error: '.$exception->getMessage());
            }

            try {
                $delivery->forceFill($result->ok
                    ? ['status' => NotificationDelivery::STATUS_SENT, 'channel' => $channel, 'provider_message_id' => $result->providerId, 'sent_at' => now(), 'error' => null]
                    : ['status' => NotificationDelivery::STATUS_FAILED, 'error' => $result->error])->save();
            } catch (Throwable) {
                // Recording the result must never break the action that triggered the notification.
            }
        }
    }

    /**
     * Client mobile messages: WhatsApp first (when enabled), SMS as the backup.
     * A row sent by WhatsApp is re-labelled channel "whatsapp"; otherwise it stays "sms".
     *
     * @return array{0: ChannelResult, 1: string}
     */
    private function sendToMobile(NotificationDelivery $delivery, WhatsAppIntegration $whatsapp, SmsIntegration $sms): array
    {
        $whatsappError = null;

        if ($whatsapp->enabled()) {
            try {
                $result = $whatsapp->send($delivery->recipient, $delivery->message);
            } catch (Throwable $exception) {
                $result = ChannelResult::fail('WhatsApp send error: '.$exception->getMessage());
            }

            if ($result->ok) {
                return [$result, NotificationDelivery::CHANNEL_WHATSAPP];
            }
            $whatsappError = $result->error;
        }

        $result = $sms->send($delivery->recipient, $delivery->message);

        if (! $result->ok && $whatsappError !== null) {
            $result = ChannelResult::fail($whatsappError.' | SMS backup: '.$result->error);
        }

        return [$result, NotificationDelivery::CHANNEL_SMS];
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
