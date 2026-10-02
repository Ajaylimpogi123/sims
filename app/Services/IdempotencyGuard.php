<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Collapses duplicate submissions of the same form.
 *
 * The client generates a key when a form is opened and sends the same key on
 * every retry of that submission. The first request to claim a key proceeds;
 * later ones are recognised as duplicates. A claim is released when the
 * operation fails, so the operator can correct the input and resubmit.
 */
class IdempotencyGuard
{
    public const SCOPE_STOCK_IN = 'stock_in';

    public const SCOPE_STOCK_OUT = 'stock_out';

    public const SCOPE_POS_CHECKOUT = 'pos_checkout';

    private const TABLE = 'tbl_idempotency_keys';

    /**
     * Attempt to claim a key.
     *
     * Returns false when the key has already been used, meaning the caller
     * should skip processing. A null or blank key is always allowed through —
     * older clients may not send one, and refusing them would break the form
     * rather than protect it.
     */
    public static function claim(string $scope, ?string $key): bool
    {
        $key = trim((string) $key);

        if ($key === '') {
            return true;
        }

        // insertOrIgnore leans on the unique index over (scope, key), which
        // makes the check-and-claim a single atomic statement rather than a
        // read followed by a write two requests could interleave on.
        $inserted = DB::table(self::TABLE)->insertOrIgnore([
            'scope' => $scope,
            'idempotency_key' => $key,
            'user_id' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $inserted > 0;
    }

    /**
     * Give a key back after a failed attempt so the same form can be retried.
     */
    public static function release(string $scope, ?string $key): void
    {
        $key = trim((string) $key);

        if ($key === '') {
            return;
        }

        DB::table(self::TABLE)
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->delete();
    }

    /**
     * Drop keys old enough that no client could still be retrying them.
     */
    public static function prune(int $days = 7): int
    {
        return DB::table(self::TABLE)
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}
