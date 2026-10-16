<?php

namespace App\Support;

use App\Models\Term;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The term a request's lists and aggregates are scoped to:
 *
 * - Students: always their own enrollment's term (no switching);
 * - website (Coordinator, Supervisor, Administrator): the term picked in
 *   the header switcher (session `term_id`, POST /term/select), falling
 *   back to the current term when unset or gone;
 * - mobile API: the `?term_id=` query parameter (422 on `term_id` when it
 *   isn't an existing term), defaulting to the current term.
 *
 * Null only when no term exists at all (lists are then unscoped).
 * Resolved once per request.
 */
final class TermContext
{
    public const SESSION_KEY = 'term_id';

    private const ATTRIBUTE = 'sims.term';

    public static function id(?Request $request = null): ?int
    {
        return self::term($request)?->id;
    }

    /**
     * @throws ValidationException an API `term_id` that isn't a term
     */
    public static function term(?Request $request = null): ?Term
    {
        $request ??= request();

        if ($request->attributes->has(self::ATTRIBUTE)) {
            return $request->attributes->get(self::ATTRIBUTE);
        }

        $term = self::resolve($request);
        $request->attributes->set(self::ATTRIBUTE, $term);

        return $term;
    }

    /**
     * Whether the request is viewing a past (archived) term.
     */
    public static function archived(?Request $request = null): bool
    {
        return (bool) self::term($request)?->isPast();
    }

    private static function resolve(Request $request): ?Term
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasRole(User::ROLE_STUDENT)) {
            $termId = $user->student?->term_id;

            return ($termId !== null ? Term::query()->find($termId) : null) ?? Term::current();
        }

        if ($request->is('api', 'api/*')) {
            $raw = $request->query('term_id');

            if ($raw === null || $raw === '') {
                return Term::current();
            }

            $term = is_string($raw) && ctype_digit($raw) && strlen($raw) <= 18
                ? Term::query()->find((int) $raw)
                : null;

            if ($term === null) {
                throw ValidationException::withMessages([
                    'term_id' => __('validation.exists', ['attribute' => 'term id']),
                ]);
            }

            return $term;
        }

        if ($user instanceof User && $request->hasSession()) {
            $selected = $request->session()->get(self::SESSION_KEY);

            if (is_int($selected) || (is_string($selected) && ctype_digit($selected))) {
                $term = Term::query()->find((int) $selected);

                if ($term !== null) {
                    return $term;
                }
            }
        }

        return Term::current();
    }
}
