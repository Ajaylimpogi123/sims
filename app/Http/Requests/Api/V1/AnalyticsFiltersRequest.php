<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape checks for the Analytics filters, stricter than the website's
 * (dates must be YYYY-MM-DD). Existence and Supervisor scoping are then
 * applied by DashboardAnalyticsService::resolveFilters(), exactly as on the
 * website.
 */
class AnalyticsFiltersRequest extends FormRequest
{
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
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            // Compared only against a string date_from: the date comparison
            // throws a TypeError (500) on an array.
            'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when(fn ($input) => is_string($input->date_from), ['after_or_equal:date_from'])],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'supervisor_id' => ['nullable', 'integer', 'min:1'],
            'student_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
