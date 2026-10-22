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
        'attempts',
        'processed_at',
        'failed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'json',
            'attempts' => 'integer',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
