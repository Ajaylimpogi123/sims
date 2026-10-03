<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A student's change to an internship report that passed validation but is
 * not allowed in the report's current state (it was reviewed in the
 * meantime). The message is shown as-is: the website flashes it, the API
 * returns it with `code: report_not_pending`.
 */
class ReportRuleException extends RuntimeException {}
