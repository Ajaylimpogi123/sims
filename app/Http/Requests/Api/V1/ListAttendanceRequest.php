<?php

namespace App\Http\Requests\Api\V1;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;

/**
 * GET /attendance: the student's own history, cursor-paginated over
 * (date, id) descending.
 */
class ListAttendanceRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:1000', $this->validCursor(...)],
        ];
    }

    /**
     * Only accept cursors this endpoint could have issued, so a tampered one
     * is a 422 instead of a silent restart or a 500.
     */
    private function validCursor(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isIssuableCursor($value)) {
            $fail('The cursor is invalid.');
        }
    }

    /**
     * Decoded by hand: Cursor::fromEncoded() assumes a well-formed payload.
     * Next-page cursors over (date, id) carry the date as the Carbon string
     * of the `date` cast, i.e. "Y-m-d 00:00:00".
     */
    private static function isIssuableCursor(string $encoded): bool
    {
        $json = base64_decode(str_replace(['-', '_'], ['+', '/'], $encoded), true);
        $parameters = $json === false ? null : json_decode($json, true);

        if (! is_array($parameters) || array_is_list($parameters)) {
            return false;
        }

        $keys = array_keys($parameters);
        sort($keys);

        if ($keys !== ['_pointsToNextItems', 'date', 'id']) {
            return false;
        }

        $date = $parameters['date'];
        $id = $parameters['id'];

        return $parameters['_pointsToNextItems'] === true
            && is_int($id) && $id > 0
            && is_string($date)
            && preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/', $date) === 1
            && self::isRealDate(substr($date, 0, 10))
            && Cursor::fromEncoded($encoded) !== null;
    }

    private static function isRealDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }
}
