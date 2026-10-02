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
        $cursor = is_string($value) ? Cursor::fromEncoded($value) : null;
        $parameters = $cursor?->toArray() ?? [];

        $createdAt = $parameters['created_at'] ?? null;
        $id = $parameters['id'] ?? null;

        $valid = $cursor !== null
            && count($parameters) === 3 // created_at, id, _pointsToNextItems
            && is_string($createdAt)
            && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $createdAt) === 1
            && is_int($id) && $id > 0;

        if (! $valid) {
            $fail('The cursor is invalid.');
        }
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
