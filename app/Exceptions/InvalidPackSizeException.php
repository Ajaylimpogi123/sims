<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a Box-denominated transaction is attempted against a product
 * whose pack_size is missing or below 1.
 *
 * Multiplying by such a pack_size would book zero pieces while the receipt
 * and the transaction record still claim boxes were moved, so the operation
 * is refused instead.
 */
class InvalidPackSizeException extends RuntimeException
{
    public static function forProduct(string $medicineName): self
    {
        return new self(
            "{$medicineName} has no valid pack size, so it cannot be transacted in boxes. "
            .'Set a pack size of at least 1 on the medicine first.'
        );
    }
}
