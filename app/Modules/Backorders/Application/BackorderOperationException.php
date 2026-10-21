<?php

namespace App\Modules\Backorders\Application;

use RuntimeException;

class BackorderOperationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }
}
