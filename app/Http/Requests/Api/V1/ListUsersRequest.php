<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\NotBoolean;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /users: the website's User Management list (role and status
 * filters) plus an app-only name/email search, page-paginated.
 */
class ListUsersRequest extends FormRequest
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
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'role_id' => ['sometimes', 'nullable', new NotBoolean, 'integer', 'min:1', 'max:2147483647'],
            'status' => ['sometimes', 'nullable', 'string', 'in:active,inactive'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /**
     * @return array{role_id: int|null, status: string|null, search: string|null}
     */
    public function filters(): array
    {
        $roleId = $this->validated('role_id');

        return [
            'role_id' => $roleId === null ? null : (int) $roleId,
            'status' => $this->validated('status'),
            'search' => $this->validated('search'),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
