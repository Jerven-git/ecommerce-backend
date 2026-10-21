<?php

namespace App\Modules\Payments\Application;

class WebhookResult
{
    public function __construct(
        public readonly string $message,
        public readonly int $status,
    ) {}

    public static function ok(string $message = 'OK'): self
    {
        return new self($message, 200);
    }

    public static function reject(string $message, int $status = 400): self
    {
        return new self($message, $status);
    }
}
