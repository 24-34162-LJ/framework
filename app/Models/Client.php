<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $table = 'clients';

    protected $primaryKey = 'client_id';

    protected $fillable = ['client_name', 'contact_person', 'email', 'phone', 'address', 'tin', 'status'];

    public $timestamps = false;

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'client_id', 'client_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'client_id', 'client_id');
    }

    public function billings(): HasMany
    {
        return $this->hasMany(Billing::class, 'client_id', 'client_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'client_id', 'client_id');
    }
}
