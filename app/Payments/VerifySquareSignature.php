<?php

namespace App\Payments;

use Illuminate\Http\Request;

class VerifySquareSignature
{
    /**
     * Square signature = base64(hmac_sha256(notification_url + raw_body, signature_key))
     */
    public static function isValid(
        string $notificationUrl,
        string $signatureKey,
        string $rawBody,
        ?string $signatureHeader
    ): bool {
        $signatureHeader = self::normalizeSignature($signatureHeader);

        if ($signatureHeader === null || $signatureKey === '') {
            return false;
        }

        $signedPayload = $notificationUrl . $rawBody;

        $computed = base64_encode(hash_hmac('sha256', $signedPayload, $signatureKey, true));

        return hash_equals($computed, $signatureHeader);
    }

    /**
     * Convenience helper for Laravel Request.
     *
     * IMPORTANT: $notificationUrl must exactly match the URL Square is configured to call.
     * Best practice: store the expected URL in config and use that, not $request->fullUrl().
     */
    public static function isValidRequest(Request $request): bool
    {
        $signatureKey = (string) config('payment.square.webhook_signature_key');
        $notificationUrl = (string) config('payment.square.webhook_notification_url');

        // If you don't store it, fallback to request URL (less reliable behind proxies)
        if ($notificationUrl === '') {
            $notificationUrl = $request->fullUrl();
        }

        $rawBody = $request->getContent();
        $signatureHeader = $request->header('x-square-hmacsha256-signature');

        return self::isValid($notificationUrl, $signatureKey, $rawBody, $signatureHeader);
    }

    private static function normalizeSignature(?string $value): ?string
    {
        if ($value === null) return null;

        $v = trim($value);
        return $v === '' ? null : $v;
    }
}
