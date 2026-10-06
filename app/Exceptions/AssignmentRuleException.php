<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * An internship assignment refused by a rule checked under the company
 * lock (slot capacity, roster, inactive company / supervisor). It is a
 * ValidationException, so the website shows it on the field like any other
 * error; the API adds `code` ($reason) to its 422.
 */
class AssignmentRuleException extends ValidationException
{
    public string $reason = '';

    public static function refuse(string $reason, string $field, string $message): self
    {
        $exception = static::withMessages([$field => $message]);
        $exception->reason = $reason;

        return $exception;
    }
}
