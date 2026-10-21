<?php

namespace App\Modules\Orders\Application;

final readonly class CartSnapshot
{
    /**
     * @param  array<int, array<string, mixed>>  $orderItems
     * @param  array<int, array{price: float, quantity: int}>  $taxItems
     * @param  array<int, array<string, mixed>>  $backorderItems
     */
    public function __construct(
        public float $rawSubtotal,
        public float $totalWeight,
        public float $totalVolumeCbm,
        public array $orderItems,
        public array $taxItems,
        public array $backorderItems,
    ) {}

    public function hasBackorders(): bool
    {
        return $this->backorderItems !== [];
    }
}
