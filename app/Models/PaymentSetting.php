<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentSetting extends Model
{
    protected $fillable = [
        'cash_enabled',
        'stripe_enabled',
        'paypal_enabled',
        'square_enabled',
    ];

    protected $casts = [
        'cash_enabled' => 'boolean',
        'stripe_enabled' => 'boolean',
        'paypal_enabled' => 'boolean',
        'square_enabled' => 'boolean',
    ];

    public function availableMethods(): array
    {
        $methods = [];

        if ($this->cash_enabled) {
            $methods[] = [
                'id' => 'cash',
                'name' => 'Cash Payment',
            ];
        }

        if ($this->stripe_enabled) {
            $methods[] = [
                'id' => 'stripe',
                'name' => 'Credit/Debit Card',
                'config' => [
                    'publishable_key' => config('payment.stripe.publishable_key'),
                ],
            ];
        }

        if ($this->paypal_enabled) {
            $methods[] = [
                'id' => 'paypal',
                'name' => 'PayPal',
                'config' => [
                    'client_id' => config('payment.paypal.client_id'),
                ],
            ];
        }

        if ($this->square_enabled) {
            $methods[] = [
                'id' => 'square',
                'name' => 'Square',
                'config' => [
                    'application_id' => config('payment.square.application_id'),
                    'location_id' => config('payment.square.location_id'),
                ],
            ];
        }

        return $methods;
    }
}
