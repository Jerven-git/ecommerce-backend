<?php

namespace App\Modules\Payments\Application;

class WebhookVerification
{
    /** @param array<string, mixed>|null $event */
    public function __construct(
        public readonly ?array $event,
        public readonly ?WebhookResult $terminalResult = null,
    ) {}

    /** @param array<string, mixed> $event */
    public static function accepted(array $event): self
    {
        return new self($event);
    }

    public static function stopped(string $message, int $status): self
    {
        return new self(null, new WebhookResult($message, $status));
    }
}
