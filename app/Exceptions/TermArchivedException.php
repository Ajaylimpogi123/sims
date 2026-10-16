<?php

namespace App\Exceptions;

use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A write to a record whose student belongs to a non-current (archived)
 * term, by anyone but the Administrator (App\Support\TermGuard). It is a
 * ValidationException on the `term` key, so the website shows it like any
 * other error (errors.term); the API answers
 * 422 {message, code: "term_archived", errors: {term: [message]}}.
 */
class TermArchivedException extends ValidationException
{
    public const CODE = 'term_archived';

    public string $reason = self::CODE;

    public static function for(Student $student): self
    {
        $label = $student->term?->label ?? 'a past term';

        return static::withMessages([
            'term' => "This record belongs to an archived term ({$label}). Only an Administrator can change it.",
        ]);
    }

    /**
     * API only; the website falls through to the usual validation redirect.
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api', 'api/*')) {
            return null;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->reason,
            'errors' => $this->errors(),
        ], $this->status);
    }
}
