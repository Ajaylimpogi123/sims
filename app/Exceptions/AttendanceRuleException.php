<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A self-service attendance action that passed validation but is not allowed
 * in the record's current state (e.g. a second time-in for today, or a
 * time-out before the time-in is approved). The message is shown to the
 * student as-is: the website flashes it, the API returns it.
 */
class AttendanceRuleException extends RuntimeException {}
