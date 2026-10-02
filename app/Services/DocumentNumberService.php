<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates sequential document numbers.
 *
 * Must be called inside the transaction that writes the document: the counter
 * increment and the insert then commit or roll back together, so a failed
 * sale does not consume a number and a successful one cannot lose it.
 */
class DocumentNumberService
{
    public const SCOPE_POS_INVOICE = 'pos_invoice';

    /**
     * Reserve the next number for a scope/period pair and return it.
     */
    public static function next(string $scope, string $period): int
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                'Document numbers must be allocated inside a transaction so the '
                .'number and the document it identifies commit together.'
            );
        }

        // Ensure the counter row exists before locking it. insertOrIgnore
        // relies on the unique index over (scope, period) to make concurrent
        // first-use of a period safe.
        DB::table('tbl_document_sequences')->insertOrIgnore([
            'scope' => $scope,
            'period' => $period,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $current = (int) DB::table('tbl_document_sequences')
            ->where('scope', $scope)
            ->where('period', $period)
            ->lockForUpdate()
            ->value('last_number');

        $next = $current + 1;

        DB::table('tbl_document_sequences')
            ->where('scope', $scope)
            ->where('period', $period)
            ->update([
                'last_number' => $next,
                'updated_at' => now(),
            ]);

        return $next;
    }

    /**
     * POS invoice number: POS-{branch}-{YYYYMMDD}-{00001}
     */
    public static function posInvoiceNumber(int $branchId): string
    {
        $period = now()->format('Ymd');
        $scope = self::SCOPE_POS_INVOICE.':'.$branchId;
        $sequence = self::next($scope, $period);

        return sprintf('POS-%d-%s-%05d', $branchId, $period, $sequence);
    }
}
