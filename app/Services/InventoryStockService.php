<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\ProductQty;
use Illuminate\Database\Eloquent\Builder;

/**
 * The only place that mutates products_qty.quantity.
 *
 * Quantities crossing this boundary are always in pieces — callers convert
 * from the transacted unit via MedicineProduct::toPieces() first. Keeping
 * increments and decrements here is what makes "every stock movement is
 * locked, validated, and logged" enforceable rather than a convention.
 */
class InventoryStockService
{
    /**
     * Largest quantity accepted on a single transaction line, in the unit the
     * operator entered. Well below any technical limit — it exists so an
     * accidental extra digit fails validation with a clear message instead of
     * overflowing a column mid-transaction.
     */
    public const MAX_TRANSACTION_QUANTITY = 1_000_000;

    /**
     * Ceiling on the converted piece count. Sized to the narrowest column in
     * the chain: tbl_inventory_movement_logs.quantity is a signed int, so a
     * movement must stay inside 2^31-1 even though products_qty.quantity is
     * an unsigned bigint.
     */
    public const MAX_TRANSACTION_PIECES = 100_000_000;

    /**
     * Statuses that quantity changes must never overwrite.
     *
     * Deleted is an operator decision and Expired is a safety state; both
     * outrank the Active/Inactive flip driven by quantity.
     */
    private const LOCKED_STATUSES = [
        ProductQty::STATUS_DELETED,
        ProductQty::STATUS_EXPIRED,
    ];

    public static function assertPiecesWithinLimit(int $quantityInPieces): void
    {
        if ($quantityInPieces > self::MAX_TRANSACTION_PIECES) {
            throw new \RuntimeException(
                'That quantity is larger than this system supports on a single line ('
                .number_format(self::MAX_TRANSACTION_PIECES).' pieces). Split it across multiple lines.'
            );
        }
    }

    /**
     * Add stock to an existing batch when lot, expiry, and shelf match; otherwise create a new batch.
     */
    public static function addStock(
        int $productId,
        int $branchId,
        int $quantityInPieces,
        ?string $lotNumber = null,
        ?string $expiry = null,
        ?string $shelfNumber = null,
    ): ProductQty {
        self::assertPiecesWithinLimit($quantityInPieces);

        $lotNumber = self::normalizeOptionalString($lotNumber);
        $shelfNumber = self::normalizeOptionalString($shelfNumber);
        $expiry = ($expiry === null || $expiry === '') ? null : $expiry;

        $existingBatch = self::findMatchingBatch(
            $productId,
            $branchId,
            $lotNumber,
            $expiry,
            $shelfNumber,
        );

        if ($existingBatch) {
            $existingBatch->increment('quantity', $quantityInPieces);
            self::afterStockAdded($existingBatch->fresh());

            return $existingBatch->fresh();
        }

        $batch = ProductQty::create([
            'product_id' => $productId,
            'quantity' => $quantityInPieces,
            'status' => ProductQty::STATUS_ACTIVE,
            'lot_number' => $lotNumber,
            'expiry' => $expiry,
            'shelf_number' => $shelfNumber,
        ]);

        self::afterStockAdded($batch);

        return $batch;
    }

    /**
     * Deduct pieces from one specific batch, addressed by primary key.
     *
     * Resolving by id rather than by lot_number matters: lot numbers are not
     * unique — the same lot received with a different expiry or shelf is a
     * separate row — so a name-based lookup could lock and decrement a
     * different batch than the operator selected.
     */
    public static function deductFromBatch(
        int $batchId,
        int $quantityInPieces,
        ?string $medicineName = null,
    ): ProductQty {
        if ($quantityInPieces < 1) {
            throw new InsufficientStockException('Quantity to deduct must be at least 1 piece.');
        }

        self::assertPiecesWithinLimit($quantityInPieces);

        $batch = ProductQty::query()
            ->whereKey($batchId)
            ->lockForUpdate()
            ->first();

        if (! $batch || $batch->isDeleted()) {
            throw InsufficientStockException::forProduct($medicineName ?? 'the selected medicine');
        }

        if ($quantityInPieces > (int) $batch->quantity) {
            throw InsufficientStockException::forLot(
                $batch->lot_number,
                (int) $batch->quantity,
                $quantityInPieces,
            );
        }

        $batch->decrement('quantity', $quantityInPieces);

        self::afterBatchQuantityChange($batch->fresh());

        return $batch->fresh();
    }

    /**
     * Deduct pieces across a product's batches, earliest expiry first.
     *
     * @return list<array{batch_id: int, pieces: int}>
     */
    public static function deductFefo(
        int $productId,
        int $piecesNeeded,
        string $medicineName,
    ): array {
        if ($piecesNeeded < 1) {
            throw new InsufficientStockException('Quantity to deduct must be at least 1 piece.');
        }

        $batches = self::fefoQuery($productId)
            ->lockForUpdate()
            ->get();

        if ((int) $batches->sum('quantity') < $piecesNeeded) {
            throw InsufficientStockException::forProduct($medicineName);
        }

        $deductions = [];
        $remaining = $piecesNeeded;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $deduct = min((int) $batch->quantity, $remaining);
            $batch->decrement('quantity', $deduct);
            self::afterBatchQuantityChange($batch->fresh());

            $deductions[] = [
                'batch_id' => (int) $batch->id,
                'pieces' => $deduct,
            ];

            $remaining -= $deduct;
        }

        return $deductions;
    }

    /**
     * Non-mutating FEFO allocation, for previewing which lots a sale will hit.
     *
     * @param  array<int, int>  $allocatedByBatch  running allocation across lines, mutated in place
     * @return list<array{batch_id: int, lot_number: ?string, expiry: ?string, shelf_number: ?string, pieces: int}>
     */
    public static function previewFefo(int $productId, int $piecesNeeded, array &$allocatedByBatch): array
    {
        $batches = self::fefoQuery($productId)->get();

        $allocations = [];
        $remaining = $piecesNeeded;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $alreadyAllocated = $allocatedByBatch[$batch->id] ?? 0;
            $available = max((int) $batch->quantity - $alreadyAllocated, 0);

            if ($available <= 0) {
                continue;
            }

            $take = min($available, $remaining);
            $allocatedByBatch[$batch->id] = $alreadyAllocated + $take;

            $allocations[] = [
                'batch_id' => (int) $batch->id,
                'lot_number' => $batch->lot_number,
                'expiry' => $batch->expiry?->format('Y-m-d'),
                'shelf_number' => $batch->shelf_number,
                'pieces' => $take,
            ];

            $remaining -= $take;
        }

        return $allocations;
    }

    private static function fefoQuery(int $productId): Builder
    {
        return ProductQty::query()
            ->where('product_id', $productId)
            ->dispensable()
            ->orderByRaw('CASE WHEN expiry IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry');
    }

    public static function findMatchingBatch(
        int $productId,
        int $branchId,
        ?string $lotNumber,
        ?string $expiry,
        ?string $shelfNumber,
    ): ?ProductQty {
        return self::matchingBatchQuery($productId, $branchId, $lotNumber, $expiry, $shelfNumber)
            ->first();
    }

    public const STOCK_IN_INTENT_INCOMPLETE = 'incomplete';

    public const STOCK_IN_INTENT_NEW = 'new';

    public const STOCK_IN_INTENT_MERGE = 'merge';

    public const STOCK_IN_INTENT_CONFLICT = 'conflict';

    public const STOCK_IN_INTENT_SHELF_SPLIT = 'shelf_split';

    /**
     * Whether a stock-in line will merge, create a new batch, or reuse a lot
     * number with different identifying details.
     */
    public static function stockInIntent(
        int $productId,
        int $branchId,
        ?string $lotNumber,
        ?string $expiry,
        ?string $shelfNumber,
    ): string {
        $lotNumber = self::normalizeOptionalString($lotNumber);

        if ($lotNumber === null) {
            return self::STOCK_IN_INTENT_INCOMPLETE;
        }

        if (self::findMatchingBatch($productId, $branchId, $lotNumber, $expiry, $shelfNumber) !== null) {
            return self::STOCK_IN_INTENT_MERGE;
        }

        $sameLot = ProductQty::query()
            ->where('product_id', $productId)
            ->whereNotIn('status', self::LOCKED_STATUSES)
            ->whereHas('product', fn (Builder $productQuery) => $productQuery->where('branch_id', $branchId))
            ->where('lot_number', $lotNumber)
            ->get();

        if ($sameLot->isEmpty()) {
            return self::STOCK_IN_INTENT_NEW;
        }

        $normalizedExpiry = ($expiry === null || $expiry === '') ? null : $expiry;

        $sameLotAndExpiry = $sameLot->filter(function (ProductQty $batch) use ($normalizedExpiry) {
            $batchExpiry = $batch->expiry?->format('Y-m-d');

            return $batchExpiry === $normalizedExpiry;
        });

        if ($sameLotAndExpiry->isEmpty()) {
            return self::STOCK_IN_INTENT_CONFLICT;
        }

        return self::STOCK_IN_INTENT_SHELF_SPLIT;
    }

    /**
     * @throws \RuntimeException when the lot number exists with a different expiry
     */
    public static function assertStockInIntentAllowed(
        int $productId,
        int $branchId,
        string $lotNumber,
        string $expiry,
        ?string $shelfNumber,
        bool $confirmDuplicateLot,
    ): void {
        $intent = self::stockInIntent($productId, $branchId, $lotNumber, $expiry, $shelfNumber);

        if ($intent !== self::STOCK_IN_INTENT_CONFLICT) {
            return;
        }

        if ($confirmDuplicateLot) {
            return;
        }

        $existing = ProductQty::query()
            ->where('product_id', $productId)
            ->whereNotIn('status', self::LOCKED_STATUSES)
            ->whereHas('product', fn (Builder $productQuery) => $productQuery->where('branch_id', $branchId))
            ->where('lot_number', trim($lotNumber))
            ->orderBy('expiry')
            ->first();

        $onFileExpiry = $existing?->expiry?->format('d M Y') ?? 'unknown';

        throw new \RuntimeException(
            "Lot {$lotNumber} already exists with expiry {$onFileExpiry}. "
            .'Confirm that you intend to create a separate batch, or match the expiry on file.'
        );
    }

    private static function matchingBatchQuery(
        int $productId,
        int $branchId,
        ?string $lotNumber,
        ?string $expiry,
        ?string $shelfNumber,
    ): Builder {
        $query = ProductQty::query()
            ->where('product_id', $productId)
            ->whereNotIn('status', self::LOCKED_STATUSES)
            ->whereHas('product', fn (Builder $productQuery) => $productQuery->where('branch_id', $branchId));

        self::applyNullableStringMatch($query, 'lot_number', $lotNumber);
        self::applyNullableDateMatch($query, 'expiry', $expiry);
        self::applyNullableStringMatch($query, 'shelf_number', $shelfNumber);

        return $query;
    }

    private static function normalizeOptionalString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function applyNullableStringMatch(
        Builder $query,
        string $column,
        ?string $value,
    ): void {
        if ($value === null) {
            $query->where(function (Builder $innerQuery) use ($column) {
                $innerQuery->whereNull($column)->orWhere($column, '');
            });

            return;
        }

        $query->where($column, $value);
    }

    private static function applyNullableDateMatch(
        Builder $query,
        string $column,
        ?string $value,
    ): void {
        if ($value === null) {
            $query->whereNull($column);

            return;
        }

        $query->whereDate($column, $value);
    }

    /**
     * Normalize lot status after a quantity change.
     * Empty active lots become Inactive; restocked lots become Active again.
     */
    public static function afterBatchQuantityChange(ProductQty $batch): void
    {
        $batch->refresh();

        if (in_array($batch->status, self::LOCKED_STATUSES, true)) {
            return;
        }

        if ((int) $batch->quantity <= 0) {
            if ($batch->status === ProductQty::STATUS_ACTIVE) {
                $batch->update(['status' => ProductQty::STATUS_INACTIVE]);
            }

            return;
        }

        if ($batch->status !== ProductQty::STATUS_ACTIVE) {
            $batch->update(['status' => ProductQty::STATUS_ACTIVE]);
        }
    }

    /**
     * Reactivate a lot when stock is added back to an existing or new batch.
     */
    public static function afterStockAdded(ProductQty $batch): void
    {
        $batch->refresh();

        if (in_array($batch->status, self::LOCKED_STATUSES, true)) {
            return;
        }

        if ((int) $batch->quantity > 0 && $batch->status !== ProductQty::STATUS_ACTIVE) {
            $batch->update(['status' => ProductQty::STATUS_ACTIVE]);
        }
    }

    /**
     * Pieces on hand, including expired batches. Use for reporting only.
     */
    public static function totalAvailableStock(int $productId): int
    {
        return (int) ProductQty::query()
            ->where('product_id', $productId)
            ->available()
            ->sum('quantity');
    }

    /**
     * Pieces that may actually be sold or dispensed.
     */
    public static function dispensableStock(int $productId, bool $lock = false): int
    {
        return (int) ProductQty::query()
            ->where('product_id', $productId)
            ->dispensable()
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->sum('quantity');
    }
}
