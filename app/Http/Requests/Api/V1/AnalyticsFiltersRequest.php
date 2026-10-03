<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

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
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'supervisor_id' => ['nullable', 'integer', 'min:1'],
            'student_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
