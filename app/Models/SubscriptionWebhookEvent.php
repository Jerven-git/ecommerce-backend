<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionWebhookEvent extends Model
{
    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'json',
        ];
    }
}
