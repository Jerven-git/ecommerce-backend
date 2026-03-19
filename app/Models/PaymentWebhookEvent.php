<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class PaymentWebhookEvent extends Model
{
    protected $fillable = [
        'provider','event_id','event_type','payload'
    ];

    protected function provider(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => strtolower(trim($value)),
        );
    }

    protected function eventType(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? strtolower(trim($value)) : $value,
        );
    }
    
    protected $casts = [
        'payload' => 'array'
    ];
}
