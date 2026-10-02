<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a deduction would take a batch (or a product's dispensable
 * pool) below zero. Controllers surface the message to the operator, so it
 * must stay human-readable.
 */
class InsufficientStockException extends RuntimeException
{
    public static function forProduct(string $medicineName): self
    {
        return new self("Insufficient Stock for {$medicineName}.");
    }

    public static function forLot(?string $lotNumber, int $available, int $requested): self
    {
        $lot = $lotNumber !== null && $lotNumber !== '' ? "Lot {$lotNumber}" : 'The selected lot';

        return new self(
            "{$lot} only has {$available} piece(s) available but {$requested} were requested. "
            .'Reduce the quantity or choose another lot.'
        );
    }
}
