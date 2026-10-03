<?php

namespace App\Http\Requests\Api\V1;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;

class ListNotificationsRequest extends FormRequest
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
            'unread' => ['sometimes', 'nullable', 'string', 'in:0,1,true,false'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:1000', $this->validCursor(...)],
        ];
    }

    /**
     * Only accept cursors this endpoint could have issued: a tampered or
     * foreign cursor would otherwise silently restart at page one, or
     * reach the query with unexpected values.
     */
    private function validCursor(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isIssuableCursor($value)) {
            $fail('The cursor is invalid.');
        }
    }

    /**
     * Decoded here rather than with Cursor::fromEncoded(), which assumes a
     * well-formed payload and errors (500) on anything else. This endpoint
     * only ever issues next-page cursors over (created_at, id), so exactly
     * those keys are accepted.
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

        if ($keys !== ['_pointsToNextItems', 'created_at', 'id']) {
            return false;
        }

        $createdAt = $parameters['created_at'];
        $id = $parameters['id'];

        return $parameters['_pointsToNextItems'] === true
            && is_int($id) && $id > 0
            && is_string($createdAt)
            && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $createdAt) === 1
            && self::isRealDateTime($createdAt)
            && Cursor::fromEncoded($encoded) !== null;
    }

    private static function isRealDateTime(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);

        return $date !== false && $date->format('Y-m-d H:i:s') === $value;
    }

    public function onlyUnread(): bool
    {
        return in_array($this->validated('unread'), ['1', 'true'], true);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }
}
