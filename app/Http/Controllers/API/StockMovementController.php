<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\InventoryStock;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StockMovementController extends BaseController
{
    /**
     * Display all stock movements.
     */
    public function index(Request $request): JsonResponse
    {
        $query = StockMovement::query()
            ->with([
                'foodItem:id,food_category_id,name,slug,sku,price,unit,status,is_available',
                'foodItem.category:id,name,slug,status',
                'inventoryStock:id,food_item_id,quantity,reserved_quantity,low_stock_quantity,location,status',
                'createdBy:id,name,email,phone,role',
                'updatedBy:id,name,email,phone,role',
            ])
            ->orderByDesc('movement_date')
            ->orderByDesc('id');

        if ($request->filled('food_item_id')) {
            $query->where('food_item_id', $request->food_item_id);
        }

        if ($request->filled('inventory_stock_id')) {
            $query->where('inventory_stock_id', $request->inventory_stock_id);
        }

        if ($request->filled('movement_type')) {
            $query->where('movement_type', $request->movement_type);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('movement_date', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('movement_date', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('reason', 'like', '%' . $request->search . '%')
                    ->orWhere('notes', 'like', '%' . $request->search . '%')
                    ->orWhere('reference_number', 'like', '%' . $request->search . '%')
                    ->orWhereHas('foodItem', function ($foodQuery) use ($request) {
                        $foodQuery->where('name', 'like', '%' . $request->search . '%')
                            ->orWhere('sku', 'like', '%' . $request->search . '%');
                    });
            });
        }

        $perPage = $request->get('per_page', 20);

        $movements = $query->paginate($perPage);

        return $this->sendResponse($movements, 'Stock movements retrieved successfully.');
    }

    /**
     * Store a new stock movement and update current inventory.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can create stock movements.');
        }

        $validator = Validator::make($request->all(), [
            'food_item_id' => 'required|exists:food_items,id',
            'movement_type' => 'required|in:initial_stock,restock,sale,adjustment,damaged,expired,return,reserved,release_reserved',
            'quantity_change' => 'required|integer|not_in:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'reference_type' => 'nullable|string|max:100',
            'reference_id' => 'nullable|integer|min:1',
            'reference_number' => 'nullable|string|max:100',
            'reason' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'movement_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        try {
            $movement = DB::transaction(function () use ($request) {
                $stock = InventoryStock::firstOrCreate(
                    ['food_item_id' => $request->food_item_id],
                    [
                        'quantity' => 0,
                        'reserved_quantity' => 0,
                        'low_stock_quantity' => 5,
                        'status' => InventoryStock::STATUS_ACTIVE,
                        'created_by' => $request->user()->id,
                        'updated_by' => $request->user()->id,
                    ]
                );

                $quantityBefore = $stock->quantity;
                $quantityAfter = $quantityBefore + (int) $request->quantity_change;

                if ($quantityAfter < 0) {
                    throw new \Exception('Not enough stock available.');
                }

                $stock->update([
                    'quantity' => $quantityAfter,
                    'last_restocked_at' => $request->quantity_change > 0 ? now() : $stock->last_restocked_at,
                    'updated_by' => $request->user()->id,
                ]);

                $unitCost = $request->unit_cost;
                $totalCost = $unitCost !== null
                    ? abs((int) $request->quantity_change) * $unitCost
                    : null;

                return StockMovement::create([
                    'inventory_stock_id' => $stock->id,
                    'food_item_id' => $request->food_item_id,
                    'movement_type' => $request->movement_type,
                    'quantity_before' => $quantityBefore,
                    'quantity_change' => (int) $request->quantity_change,
                    'quantity_after' => $quantityAfter,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'reference_type' => $request->reference_type,
                    'reference_id' => $request->reference_id,
                    'reference_number' => $request->reference_number,
                    'reason' => $request->reason,
                    'notes' => $request->notes,
                    'movement_date' => $request->movement_date ?? now(),
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);
            });

            $movement->load([
                'foodItem:id,food_category_id,name,slug,sku,price,unit,status,is_available',
                'foodItem.category:id,name,slug,status',
                'inventoryStock:id,food_item_id,quantity,reserved_quantity,low_stock_quantity,location,status',
                'createdBy:id,name,email,phone,role',
                'updatedBy:id,name,email,phone,role',
            ]);

            return $this->sendCreated($movement, 'Stock movement created successfully.');
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }
    }

    /**
     * Display one stock movement.
     */
    public function show(StockMovement $stockMovement): JsonResponse
    {
        $stockMovement->load([
            'foodItem:id,food_category_id,name,slug,sku,price,unit,status,is_available',
            'foodItem.category:id,name,slug,status',
            'inventoryStock:id,food_item_id,quantity,reserved_quantity,low_stock_quantity,location,status',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($stockMovement, 'Stock movement retrieved successfully.');
    }

    /**
     * Update stock movement notes only.
     *
     * Important:
     * Quantity cannot be updated here because this is an audit/history record.
     * If stock is wrong, create a new adjustment movement instead.
     */
    public function update(Request $request, StockMovement $stockMovement): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can update stock movement notes.');
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'movement_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $stockMovement->update([
            'reason' => $request->reason ?? $stockMovement->reason,
            'notes' => $request->notes ?? $stockMovement->notes,
            'movement_date' => $request->movement_date ?? $stockMovement->movement_date,
            'updated_by' => $request->user()->id,
        ]);

        $stockMovement->load([
            'foodItem:id,food_category_id,name,slug,sku,price,unit,status,is_available',
            'foodItem.category:id,name,slug,status',
            'inventoryStock:id,food_item_id,quantity,reserved_quantity,low_stock_quantity,location,status',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($stockMovement, 'Stock movement updated successfully.');
    }

    /**
     * Soft delete movement record.
     *
     * This does not reverse stock quantity.
     */
    public function destroy(Request $request, StockMovement $stockMovement): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can delete stock movements.');
        }

        $stockMovement->delete();

        return $this->sendResponse([], 'Stock movement deleted successfully.');
    }

    /**
     * Restore deleted movement.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can restore stock movements.');
        }

        $movement = StockMovement::onlyTrashed()->find($id);

        if (!$movement) {
            return $this->sendNotFound('Deleted stock movement not found.');
        }

        $movement->restore();

        $movement->load([
            'foodItem:id,food_category_id,name,slug,sku,price,unit,status,is_available',
            'foodItem.category:id,name,slug,status',
            'inventoryStock:id,food_item_id,quantity,reserved_quantity,low_stock_quantity,location,status',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($movement, 'Stock movement restored successfully.');
    }

    /**
     * Stock movement summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $query = StockMovement::query();

        if ($request->filled('food_item_id')) {
            $query->where('food_item_id', $request->food_item_id);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('movement_date', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('movement_date', '<=', $request->to_date);
        }

        $totalAdded = (clone $query)
            ->where('quantity_change', '>', 0)
            ->sum('quantity_change');

        $totalReduced = abs((clone $query)
            ->where('quantity_change', '<', 0)
            ->sum('quantity_change'));

        $movementCount = (clone $query)->count();

        $byType = (clone $query)
            ->select('movement_type', DB::raw('COUNT(*) as total_records'), DB::raw('SUM(quantity_change) as total_quantity'))
            ->groupBy('movement_type')
            ->orderBy('movement_type')
            ->get();

        return $this->sendResponse([
            'total_added' => $totalAdded,
            'total_reduced' => $totalReduced,
            'movement_count' => $movementCount,
            'by_type' => $byType,
        ], 'Stock movement summary retrieved successfully.');
    }
}