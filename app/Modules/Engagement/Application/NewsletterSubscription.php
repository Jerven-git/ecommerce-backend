<?php

namespace App\Modules\Engagement\Application;

use App\Models\Discount;
use App\Models\SiteConfig;
use App\Models\Subscriber;
use Illuminate\Support\Str;

class NewsletterSubscription
{
    public function subscribe(string $email, string $source): array
    {
        $email = strtolower(trim($email));
        $existing = Subscriber::where('email', $email)->first();

        if ($existing) {
            return [
                'created' => false,
                'message' => 'You\'re already subscribed!',
                'discount_code' => $source === 'welcome_popup' ? $existing->discount_code_sent : null,
            ];
        }

        $discountCode = $source === 'welcome_popup' ? $this->welcomeDiscount($email) : null;
        Subscriber::create([
            'email' => $email,
            'source' => $source,
            'discount_code_sent' => $discountCode,
        ]);

        return ['created' => true, 'message' => 'Subscribed successfully!', 'discount_code' => $discountCode];
    }

    private function welcomeDiscount(string $email): ?string
    {
        $templateId = SiteConfig::first()?->welcome_popup_discount_id;
        $template = $templateId ? Discount::find($templateId) : null;
        if (! $template?->is_active) {
            return null;
        }

        do {
            $code = 'WELCOME-'.strtoupper(Str::random(6));
        } while (Discount::where('code', $code)->exists());

        Discount::create([
            'code' => $code,
            'description' => 'Welcome popup discount for '.$email,
            'type' => $template->type,
            'value' => $template->value,
            'min_order_amount' => $template->min_order_amount,
            'max_uses' => 1,
            'is_active' => true,
            'valid_until' => $template->valid_until,
        ]);

        return $code;
    }
}
