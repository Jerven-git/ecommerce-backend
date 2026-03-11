<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Shipment extends Model
{
    protected $fillable = [
        'order_id',
        'tracking_number',
        'carrier',
        'status',
        'shipped_at',
        'delivered_at',
    ];

    protected $casts = [
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public static function generateTrackingNumber(): string
    {
        do {
            $number = 'SSU-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (static::where('tracking_number', $number)->exists());

        return $number;
    }
}
