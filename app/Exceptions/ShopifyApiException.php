<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised for Shopify API failures. Messages must never contain tokens.
 */
class ShopifyApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }
}
