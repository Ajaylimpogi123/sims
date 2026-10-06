<?php

namespace App\Http\Requests\Api\V1;

use App\Services\InternshipAssignmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /assignments: the website's Internship Assignment list,
 * page-paginated, with search and filters.
 */
class ListAssignmentsRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    /** A positive id, or "none" for unassigned. */
    private const ID_OR_NONE = '/^(none|[1-9][0-9]{0,17})$/';

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
            'internship_status' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(InternshipAssignmentService::STATUSES))],
            'status' => ['sometimes', 'nullable', 'string', 'in:active,inactive'],
            'company_id' => ['sometimes', 'nullable', 'regex:'.self::ID_OR_NONE],
            'supervisor_id' => ['sometimes', 'nullable', 'regex:'.self::ID_OR_NONE],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function messages(): array
    {
        return [
            'company_id.regex' => 'The company id must be a company id or "none".',
            'supervisor_id.regex' => 'The supervisor id must be a supervisor id or "none".',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return collect(['search', 'internship_status', 'status', 'company_id', 'supervisor_id'])
            ->mapWithKeys(fn (string $key) => [$key => $this->validated($key)])
            ->all();
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
