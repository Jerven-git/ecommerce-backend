<?php

namespace App\Payments;

class VerifySquareSignature
{
    public static function isValid(string $notificationUrl, string $signatureKey, string $rawBody, ?string $signatureHeader): bool
    {
        if (!$signatureHeader) return false;

        // Square uses notification URL + raw body
        $signedPayload = $notificationUrl . $rawBody;

        $computed = base64_encode(hash_hmac('sha256', $signedPayload, $signatureKey, true));

        // constant-time compare
        return hash_equals($computed, $signatureHeader);
    }
}