<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A change to a supervisor evaluation that is not allowed in its current
 * state (it was submitted, locked or reopened in the meantime). Thrown by
 * EvaluationService after re-checking the state under a row lock; the
 * message is EvaluationPolicy's wording. The website answers 403 with it
 * (as the policy would have), the API a 422 with `code: evaluation_rule`.
 */
class EvaluationRuleException extends RuntimeException {}
