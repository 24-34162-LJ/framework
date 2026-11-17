<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One SMS or email attempt for a notification, with its result. */
class NotificationDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_EMAIL = 'email';

    /** An "sms" delivery that was actually sent by WhatsApp (see SendNotificationDeliveries). */
    public const CHANNEL_WHATSAPP = 'whatsapp';

    protected $table = 'notification_deliveries';

    protected $primaryKey = 'delivery_id';

    public $timestamps = false;

    protected $fillable = [
        'notification_id', 'shipment_id', 'channel', 'recipient', 'subject', 'message',
        'status', 'provider_message_id', 'error', 'created_at', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class, 'notification_id', 'notification_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id', 'shipment_id');
    }
}
