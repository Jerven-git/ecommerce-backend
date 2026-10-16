<?php

namespace App\Support\WebsiteEditor;

use RuntimeException;

class WebsiteDraftPublishException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }
}
