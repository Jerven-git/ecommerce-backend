<?php

namespace App\Modules\Catalog\Application;

use RuntimeException;

class CatalogOperationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
