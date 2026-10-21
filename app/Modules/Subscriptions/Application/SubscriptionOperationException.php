<?php

namespace App\Modules\Subscriptions\Application;

use RuntimeException;

class SubscriptionOperationException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
