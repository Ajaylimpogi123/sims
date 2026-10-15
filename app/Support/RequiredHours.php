<?php

namespace App\Support;

/**
 * A student's required hours (students.required_hours), as set from
 * Attendance Monitoring (Supervisor, Administrator) and Internship
 * Assignment (Coordinator, Administrator), on the website and the API.
 * Both paths validate and normalise it here, so they accept exactly the
 * same input.
 *
 * "Not set" is stored as null: empty input and every spelling of zero
 * ("0", "-0", "+0", ...) mean not set, so the column never holds 0.
 */
final class RequiredHours
{
    /**
     * A sensible cap (lead decision, 2026-10-15): a set value is 1–2000.
     * Well under the unsigned INT column.
     */
    public const MAX = 2000;

    public const LOCKED_MESSAGE = 'Required hours can\'t be cleared while the internship is Ongoing.';

    /**
     * Validation rules for the (already blank-normalised, see prepare())
     * value. Arrays, booleans, fractions, negatives, text and anything
     * over MAX are refused. `min:0` (not 1) so "-0" / "+0" validate and
     * normalize() turns them into "not set" like 0; a set value is
     * therefore always 1–MAX.
     *
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return ['nullable', 'numeric', 'integer', 'min:0', 'max:'.self::MAX];
    }

    /**
     * Blank input ("" / null / false-y) becomes null before validation, so
     * `nullable` lets it through as "not set".
     */
    public static function prepare(mixed $value): mixed
    {
        return $value ?: null;
    }

    /**
     * The validated value as stored: a positive int, or null for not set.
     */
    public static function normalize(mixed $value): ?int
    {
        return $value === null || (int) $value === 0 ? null : (int) $value;
    }
}
