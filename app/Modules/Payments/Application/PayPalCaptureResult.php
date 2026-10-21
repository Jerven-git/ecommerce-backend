<?php

namespace App\Modules\Payments\Application;

class PayPalCaptureResult
{
    public function __construct(
        public readonly bool $successful,
        public readonly bool $completed,
        public readonly mixed $captureId = null,
        public readonly mixed $payload = null,
        public readonly ?int $httpStatus = null,
    ) {}
}
