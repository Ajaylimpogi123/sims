<?php

namespace App\Exceptions;

use App\Services\InternshipReportService;
use RuntimeException;

/**
 * A student's change to an internship report that passed validation but is
 * not allowed right now: the report was reviewed in the meantime
 * (`report_not_pending`), or the student's internship isn't ongoing
 * (`report_rule`, see notOngoing()). The message is shown as-is: the
 * website flashes it, the API returns it with `code: $apiCode`.
 */
class ReportRuleException extends RuntimeException
{
    public const NOT_PENDING = 'report_not_pending';

    public const RULE = 'report_rule';

    public string $apiCode = self::NOT_PENDING;

    public static function notOngoing(): self
    {
        $exception = new self(InternshipReportService::NOT_ONGOING_MESSAGE);
        $exception->apiCode = self::RULE;

        return $exception;
    }

    public function isNotOngoing(): bool
    {
        return $this->apiCode === self::RULE;
    }
}
