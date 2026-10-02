<?php

namespace Tests\Feature;

use App\Models\ProductQty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsWestpoint;
use Tests\TestCase;

class BatchDeactivationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsWestpoint;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWestpoint();
    }

    public function test_pos_checkout_deactivates_empty_lot_but_keeps_product_active(): void
    {
        $this->batch->update(['quantity' => 2]);

        $cartResponse = $this->actingAsWithSession($this->staff)
            ->postJson('/pos/cart/items', [
                'product_id' => $this->product->id,
                'unit_type' => 'Piece',
            ]);

        $cartId = $cartResponse->json('id');

        $this->actingAsWithSession($this->staff)
            ->post('/pos', [
                'cart_id' => $cartId,
                'items' => [[
                    'product_id' => $this->product->id,
                    'unit_type' => 'Piece',
                    'quantity_sold' => 2,
                ]],
                'payment_method' => 'cash',
                'discount_amount' => 0,
                'amount_received' => 20,
            ])
            ->assertRedirect(route('pos.index'));

        $this->assertDatabaseHas('tbl_products', [
            'id' => $this->product->id,
            'status' => 'Active',
        ]);

        $this->assertDatabaseHas('products_qty', [
            'id' => $this->batch->id,
            'quantity' => 0,
            'status' => 'Inactive',
        ]);
    }

    public function test_partial_lot_depletion_does_not_deactivate_product_when_other_lots_have_stock(): void
    {
        // Expires after the seeded lot so FEFO drains the seeded lot first;
        // the point of this test is what happens to the *other* lot once one
        // is emptied.
        $secondBatch = ProductQty::create([
            'product_id' => $this->product->id,
            'quantity' => 50,
            'status' => 'Active',
            'lot_number' => 'LOT-002',
            'expiry' => now()->addMonths(18)->toDateString(),
        ]);

        $this->batch->update(['quantity' => 2]);

        $cartResponse = $this->actingAsWithSession($this->staff)
            ->postJson('/pos/cart/items', [
                'product_id' => $this->product->id,
                'unit_type' => 'Piece',
            ]);

        $cartId = $cartResponse->json('id');

        $this->actingAsWithSession($this->staff)
            ->post('/pos', [
                'cart_id' => $cartId,
                'items' => [[
                    'product_id' => $this->product->id,
                    'unit_type' => 'Piece',
                    'quantity_sold' => 2,
                ]],
                'payment_method' => 'cash',
                'discount_amount' => 0,
                'amount_received' => 20,
            ])
            ->assertRedirect(route('pos.index'));

        $this->assertDatabaseHas('tbl_products', [
            'id' => $this->product->id,
            'status' => 'Active',
        ]);

        $this->assertDatabaseHas('products_qty', [
            'id' => $this->batch->id,
            'status' => 'Inactive',
        ]);

        $this->assertDatabaseHas('products_qty', [
            'id' => $secondBatch->id,
            'status' => 'Active',
            'quantity' => 50,
        ]);
    }

    public function test_stock_in_reactivates_inactive_lot(): void
    {
        $this->batch->update(['quantity' => 0, 'status' => 'Inactive']);

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
                    'quantity_received' => 50,
                    'unit_type' => 'Piece',
                ]],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('products_qty', [
            'id' => $this->batch->id,
            'quantity' => 50,
            'status' => 'Active',
        ]);

        $this->assertDatabaseHas('tbl_products', [
            'id' => $this->product->id,
            'status' => 'Active',
        ]);
    }
}
