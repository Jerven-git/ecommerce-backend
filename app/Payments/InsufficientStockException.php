<?php

namespace App\Payments;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public int $productId,
        public string $productName,
        public int $available,
        public int $required
    ) {
        parent::__construct("Insufficient stock for product: {$productName} (available {$available}, required {$required})");
    }
}
