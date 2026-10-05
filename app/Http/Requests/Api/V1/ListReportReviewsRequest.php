<?php

namespace App\Http\Requests\Api\V1;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;

/**
 * GET /report-reviews: the website's Report Reviews list (pending first,
 * then newest period), optionally filtered, cursor-paginated over
 * (status asc, period_start desc, id desc). Filters only narrow the list;
 * the caller's scope is applied by the controller regardless.
 */
class ListReportReviewsRequest extends FormRequest
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
            'type' => ['sometimes', 'nullable', 'string', 'in:daily,weekly'],
            'status' => ['sometimes', 'nullable', 'string', 'in:pending,reviewed'],
            'student_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
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
     * Next-page cursors carry period_start as the Carbon string of its date
     * cast, i.e. "Y-m-d 00:00:00".
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

        if ($keys !== ['_pointsToNextItems', 'id', 'period_start', 'status']) {
            return false;
        }

        $date = $parameters['period_start'];
        $id = $parameters['id'];

        return $parameters['_pointsToNextItems'] === true
            && in_array($parameters['status'], ['pending', 'reviewed'], true)
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

    public function type(): ?string
    {
        return $this->validated('type');
    }

    public function status(): ?string
    {
        return $this->validated('status');
    }

    public function studentId(): ?int
    {
        $id = $this->validated('student_id');

        return $id === null ? null : (int) $id;
    }

    public function search(): ?string
    {
        $search = trim((string) $this->validated('search'));

        return $search === '' ? null : $search;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }
}
