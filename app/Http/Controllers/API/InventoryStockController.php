<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\InventoryStock;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InventoryStockController extends BaseController
{
    /**
     * Display all inventory stock records.
     */
    public function index(Request $request): JsonResponse
    {
        $query = InventoryStock::query()
            ->with([
                'foodItem:id,food_category_id,name,slug,sku,price,unit,status,is_available',
                'foodItem.category:id,name,slug,status',
                'createdBy:id,name,email,phone,role',
                'updatedBy:id,name,email,phone,role',
            ])
            ->orderByDesc('id');

        if ($request->filled('food_item_id')) {
            $query->where('food_item_id', $request->food_item_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('low_stock')) {
            $lowStock = filter_var($request->low_stock, FILTER_VALIDATE_BOOLEAN);

            if ($lowStock) {
                $query->whereColumn('quantity', '<=', 'low_stock_quantity');
            }
        }

        if ($request->filled('search')) {
            $query->whereHas('foodItem', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('sku', 'like', '%' . $request->search . '%');
            });
        }

        $perPage = $request->get('per_page', 20);

        $stocks = $query->paginate($perPage);

        return $this->sendResponse($stocks, 'Inventory stocks retrieved successfully.');
    }

    /**
     * Store a new inventory stock record.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can manage inventory stock.');
        }

        $validator = Validator::make($request->all(), [
            'food_item_id' => 'required|exists:food_items,id|unique:inventory_stocks,food_item_id',
            'quantity' => 'required|integer|min:0',
            'reserved_quantity' => 'nullable|integer|min:0',
            'low_stock_quantity' => 'nullable|integer|min:0',
            'location' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,inactive',
            'reason' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $stock = DB::transaction(function () use ($request) {
            $stock = InventoryStock::create([
                'food_item_id' => $request->food_item_id,
                'quantity' => $request->quantity,
                'reserved_quantity' => $request->reserved_quantity ?? 0,
                'low_stock_quantity' => $request->low_stock_quantity ?? 5,
                'location' => $request->location,
                'status' => $request->status ?? InventoryStock::STATUS_ACTIVE,
                'last_restocked_at' => $request->quantity > 0 ? now() : null,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            if ($request->quantity > 0) {
                StockMovement::create([
                    'inventory_stock_id' => $stock->id,
                    'food_item_id' => $stock->food_item_id,
                    'movement_type' => StockMovement::TYPE_INITIAL_STOCK,
                    'quantity_before' => 0,
                    'quantity_change' => $request->quantity,
                    'quantity_after' => $request->quantity,
                    'reason' => $request->reason ?? 'Initial stock created',
                    'notes' => $request->notes,
                    'movement_date' => now(),
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);
            }

            return $stock;
        });

        $stock->load([
            'foodItem:id,food_category_id,name,slug,sku,price,unit,status,is_available',
            'foodItem.category:id,name,slug,status',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendCreated($stock, 'Inventory stock created successfully.');
    }

    /**
     * Display one inventory stock record.
     */
    public function show(InventoryStock $inventoryStock): JsonResponse
    {
        $inventoryStock->load([
            'foodItem:id,food_category_id,name,slug,sku,price,unit,status,is_available',
            'foodItem.category:id,name,slug,status',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
            'stockMovements',
        ]);

        return $this->sendResponse($inventoryStock, 'Inventory stock retrieved successfully.');
    }

    /**
     * Update an inventory stock record directly.
     */
    public function update(Request $request, InventoryStock $inventoryStock): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can update inventory stock.');
        }

        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:0',
            'reserved_quantity' => 'nullable|integer|min:0',
            'low_stock_quantity' => 'nullable|integer|min:0',
            'location' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,inactive',
            'reason' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $inventoryStock = DB::transaction(function () use ($request, $inventoryStock) {
            $oldQuantity = $inventoryStock->quantity;
            $newQuantity = $request->quantity;
            $quantityChange = $newQuantity - $oldQuantity;

            $inventoryStock->update([
                'quantity' => $newQuantity,
                'reserved_quantity' => $request->reserved_quantity ?? $inventoryStock->reserved_quantity,
                'low_stock_quantity' => $request->low_stock_quantity ?? $inventoryStock->low_stock_quantity,
                'location' => $request->location,
                'status' => $request->status ?? $inventoryStock->status,
                'last_restocked_at' => $quantityChange > 0 ? now() : $inventoryStock->last_restocked_at,
                'updated_by' => $request->user()->id,
            ]);

            if ($quantityChange !== 0) {
                StockMovement::create([
                    'inventory_stock_id' => $inventoryStock->id,
                    'food_item_id' => $inventoryStock->food_item_id,
                    'movement_type' => StockMovement::TYPE_ADJUSTMENT,
                    'quantity_before' => $oldQuantity,
                    'quantity_change' => $quantityChange,
                    'quantity_after' => $newQuantity,
                    'reason' => $request->reason ?? 'Manual stock adjustment',
                    'notes' => $request->notes,
                    'movement_date' => now(),
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);
            }

            return $inventoryStock;
        });

        $inventoryStock->load([
            'foodItem:id,food_category_id,name,slug,sku,price,unit,status,is_available',
            'foodItem.category:id,name,slug,status',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($inventoryStock, 'Inventory stock updated successfully.');
    }

    /**
     * Delete inventory stock record.
     */
    public function destroy(Request $request, InventoryStock $inventoryStock): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can delete inventory stock.');
        }

        $inventoryStock->delete();

        return $this->sendResponse([], 'Inventory stock deleted successfully.');
    }

    /**
     * Restore deleted inventory stock.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can restore inventory stock.');
        }

        $stock = InventoryStock::onlyTrashed()->find($id);

        if (!$stock) {
            return $this->sendNotFound('Deleted inventory stock not found.');
        }

        $stock->restore();

        $stock->load([
            'foodItem:id,food_category_id,name,slug,sku,price,unit,status,is_available',
            'foodItem.category:id,name,slug,status',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($stock, 'Inventory stock restored successfully.');
    }

    /**
     * Add stock quantity.
     */
    public function addStock(Request $request, InventoryStock $inventoryStock): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can add stock.');
        }

        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1',
            'unit_cost' => 'nullable|numeric|min:0',
            'reason' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'reference_number' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $inventoryStock = DB::transaction(function () use ($request, $inventoryStock) {
            $quantityBefore = $inventoryStock->quantity;
            $quantityAfter = $quantityBefore + $request->quantity;

            $inventoryStock->update([
                'quantity' => $quantityAfter,
                'last_restocked_at' => now(),
                'updated_by' => $request->user()->id,
            ]);

            StockMovement::create([
                'inventory_stock_id' => $inventoryStock->id,
                'food_item_id' => $inventoryStock->food_item_id,
                'movement_type' => StockMovement::TYPE_RESTOCK,
                'quantity_before' => $quantityBefore,
                'quantity_change' => $request->quantity,
                'quantity_after' => $quantityAfter,
                'unit_cost' => $request->unit_cost,
                'total_cost' => $request->unit_cost ? $request->unit_cost * $request->quantity : null,
                'reference_number' => $request->reference_number,
                'reason' => $request->reason ?? 'Stock added',
                'notes' => $request->notes,
                'movement_date' => now(),
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            return $inventoryStock;
        });

        $inventoryStock->load([
            'foodItem:id,food_category_id,name,slug,sku,price,unit,status,is_available',
            'foodItem.category:id,name,slug,status',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($inventoryStock, 'Stock added successfully.');
    }

    /**
     * Reduce stock quantity.
     */
    public function reduceStock(Request $request, InventoryStock $inventoryStock): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can reduce stock.');
        }

        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1',
            'movement_type' => 'nullable|in:adjustment,damaged,expired,sale',
            'reason' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'reference_number' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        if ($request->quantity > $inventoryStock->quantity) {
            return $this->sendError('Not enough stock available.', [
                'available_quantity' => $inventoryStock->quantity,
                'requested_quantity' => $request->quantity,
            ], 400);
        }

        $inventoryStock = DB::transaction(function () use ($request, $inventoryStock) {
            $quantityBefore = $inventoryStock->quantity;
            $quantityAfter = $quantityBefore - $request->quantity;

            $inventoryStock->update([
                'quantity' => $quantityAfter,
                'updated_by' => $request->user()->id,
            ]);

            StockMovement::create([
                'inventory_stock_id' => $inventoryStock->id,
                'food_item_id' => $inventoryStock->food_item_id,
                'movement_type' => $request->movement_type ?? StockMovement::TYPE_ADJUSTMENT,
                'quantity_before' => $quantityBefore,
                'quantity_change' => -abs($request->quantity),
                'quantity_after' => $quantityAfter,
                'reference_number' => $request->reference_number,
                'reason' => $request->reason ?? 'Stock reduced',
                'notes' => $request->notes,
                'movement_date' => now(),
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            return $inventoryStock;
        });

        $inventoryStock->load([
            'foodItem:id,food_category_id,name,slug,sku,price,unit,status,is_available',
            'foodItem.category:id,name,slug,status',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($inventoryStock, 'Stock reduced successfully.');
    }

    /**
     * Display low-stock items.
     */
    public function lowStock(Request $request): JsonResponse
    {
        $query = InventoryStock::query()
            ->with([
                'foodItem:id,food_category_id,name,slug,sku,price,unit,status,is_available',
                'foodItem.category:id,name,slug,status',
                'updatedBy:id,name,email,phone,role',
            ])
            ->whereColumn('quantity', '<=', 'low_stock_quantity')
            ->orderBy('quantity');

        $perPage = $request->get('per_page', 20);

        $stocks = $query->paginate($perPage);

        return $this->sendResponse($stocks, 'Low-stock items retrieved successfully.');
    }
}