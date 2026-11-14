<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable(['full_name', 'email', 'password_hash', 'role', 'client_id', 'status', 'phone', 'sms_enabled'])]
#[Hidden(['password_hash', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected $table = 'users';

    protected $primaryKey = 'user_id';

    protected $authPasswordName = 'password_hash';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sms_enabled' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'client_id');
    }

    public function assignedShipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'assigned_staff_id', 'user_id');
    }

    public function archivedShipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'archived_by', 'user_id');
    }

    public function customsUpdates(): HasMany
    {
        return $this->hasMany(CustomsProcessing::class, 'updated_by', 'user_id');
    }

    public function shipmentStatusUpdates(): HasMany
    {
        return $this->hasMany(ShipmentStatusUpdate::class, 'updated_by', 'user_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'user_id', 'user_id');
    }

    public function uploadedShipmentDocuments(): HasMany
    {
        return $this->hasMany(ShipmentDocument::class, 'uploaded_by', 'user_id');
    }

    public function createdBillings(): HasMany
    {
        return $this->hasMany(Billing::class, 'created_by', 'user_id');
    }

    public function verifiedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'verified_by', 'user_id');
    }

    public function recordedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'recorded_by', 'user_id');
    }

    public function transactionHistoryEntries(): HasMany
    {
        return $this->hasMany(TransactionHistory::class, 'performed_by', 'user_id');
    }

    public function activityEntries(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'performed_by', 'user_id');
    }
}
