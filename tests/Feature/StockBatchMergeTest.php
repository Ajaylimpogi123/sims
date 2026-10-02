<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsWestpoint;
use Tests\TestCase;

class StockBatchMergeTest extends TestCase
{
    use RefreshDatabase;
    use SeedsWestpoint;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWestpoint();
    }

    public function test_add_stock_merges_matching_lot_expiry_and_shelf(): void
    {
        $this->batch->update([
            'quantity' => 100,
            'expiry' => now()->addYear()->toDateString(),
            'shelf_number' => 'A-01',
        ]);

        $this->actingAsWithSession($this->staff)
            ->post('/medicine-inventory/stock', [
                'product_id' => $this->product->id,
                'boxes_received' => 5,
                'lot_number' => 'LOT-001',
                'expiry' => now()->addYear()->toDateString(),
                'shelf_number' => 'A-01',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('products_qty', 1);
        $this->assertDatabaseHas('products_qty', [
            'id' => $this->batch->id,
            'quantity' => 100 + (5 * $this->product->pack_size),
        ]);
    }

    public function test_stock_in_creates_new_row_when_shelf_differs(): void
    {
        $this->batch->update([
            'quantity' => 100,
            'expiry' => now()->addYear()->toDateString(),
            'shelf_number' => 'A-01',
        ]);

        $this->actingAsWithSession($this->staff)
            ->post('/stock-in', [
                'supplier_name' => 'Test Supplier',
                'delivery_date' => now()->toDateString(),
                'branch_id' => $this->branchA->id,
                'received_by' => 'Staff User',
                'items' => [[
                    'pd_id' => $this->product->id,
                    'batch_number' => 'LOT-001',
                    'expiry_date' => now()->addYear()->toDateString(),
                    'quantity_received' => 25,
                    'shelf_number' => 'B-02',
                    'unit_type' => 'Piece',
                ]],
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('products_qty', 2);
        $this->assertDatabaseHas('products_qty', [
            'id' => $this->batch->id,
            'quantity' => 100,
            'shelf_number' => 'A-01',
        ]);
        $this->assertDatabaseHas('products_qty', [
            'product_id' => $this->product->id,
            'quantity' => 25,
            'shelf_number' => 'B-02',
        ]);
    }

    public function test_stock_in_merges_matching_lot_expiry_and_shelf(): void
    {
        $this->batch->update([
            'quantity' => 40,
            'expiry' => now()->addMonths(6)->toDateString(),
            'shelf_number' => 'C-03',
        ]);

        $this->actingAsWithSession($this->staff)
            ->post('/stock-in', [
                'supplier_name' => 'Test Supplier',
                'delivery_date' => now()->toDateString(),
                'branch_id' => $this->branchA->id,
                'received_by' => 'Staff User',
                'items' => [[
                    'pd_id' => $this->product->id,
                    'batch_number' => 'LOT-001',
                    'expiry_date' => now()->addMonths(6)->toDateString(),
                    'quantity_received' => 60,
                    'shelf_number' => 'C-03',
                    'unit_type' => 'Piece',
                ]],
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('products_qty', 1);
        $this->assertDatabaseHas('products_qty', [
            'id' => $this->batch->id,
            'quantity' => 100,
            'shelf_number' => 'C-03',
        ]);
    }
}
