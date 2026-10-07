<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Pair with `integer` on id fields: Laravel's `integer` rule accepts a
 * JSON `true` (it reads as 1), which would silently select record 1.
 * Fails with the integer message, so it reads like the rule it completes.
 */
class NotBoolean implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_bool($value)) {
            $fail('validation.integer')->translate();
        }
    }
}
