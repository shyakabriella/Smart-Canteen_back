<?php

namespace App\Http\Controllers\API;

use App\Models\FoodItem;
use App\Models\InventoryStock;
use App\Models\LowStockAlert;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class InventoryStockController extends BaseController
{
    /**
     * Display inventory stock records.
     */
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can view inventory stock.'
            );
        }

        $query = InventoryStock::query()
            ->with($this->defaultRelations())
            ->orderByDesc('id');

        if ($request->filled('food_item_id')) {
            $query->where('food_item_id', $request->food_item_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->boolean('low_stock')) {
            $query->whereRaw(
                '(quantity - COALESCE(reserved_quantity, 0)) <= low_stock_quantity'
            );
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->whereHas('foodItem', function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('sku', 'like', '%' . $search . '%');
            });
        }

        $perPage = min(max((int) $request->get('per_page', 20), 1), 200);
        $stocks = $query->paginate($perPage);

        $stocks->getCollection()->transform(
            fn (InventoryStock $stock) => $this->prepareStockResponse($stock)
        );

        return $this->sendResponse(
            $stocks,
            'Inventory stocks retrieved successfully.'
        );
    }

    /**
     * Store a new inventory stock record.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can manage inventory stock.'
            );
        }

        $validator = Validator::make($request->all(), [
            'food_item_id' => [
                'required',
                'integer',
                'exists:food_items,id',
                'unique:inventory_stocks,food_item_id',
            ],
            'quantity' => ['required', 'integer', 'min:0'],
            'reserved_quantity' => ['nullable', 'integer', 'min:0'],
            'low_stock_quantity' => ['nullable', 'integer', 'min:0'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,inactive'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $validator->after(function ($validator) use ($request) {
            $quantity = (int) $request->input('quantity', 0);
            $reserved = (int) $request->input('reserved_quantity', 0);

            if ($reserved > $quantity) {
                $validator->errors()->add(
                    'reserved_quantity',
                    'Reserved quantity cannot exceed total quantity.'
                );
            }
        });

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        try {
            $stock = DB::transaction(function () use ($request) {
                $foodItem = FoodItem::query()
                    ->lockForUpdate()
                    ->findOrFail($request->food_item_id);

                $threshold = $request->has('low_stock_quantity')
                    ? (int) $request->low_stock_quantity
                    : (int) ($foodItem->low_stock_quantity ?? 5);

                $stock = InventoryStock::create([
                    'food_item_id' => $foodItem->id,
                    'quantity' => (int) $request->quantity,
                    'reserved_quantity' => (int) ($request->reserved_quantity ?? 0),
                    'low_stock_quantity' => $threshold,
                    'location' => $request->location,
                    'status' => $request->status ?? InventoryStock::STATUS_ACTIVE,
                    'last_restocked_at' => (int) $request->quantity > 0
                        ? now()
                        : null,
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);

                if ((int) $request->quantity > 0) {
                    StockMovement::create([
                        'inventory_stock_id' => $stock->id,
                        'food_item_id' => $stock->food_item_id,
                        'movement_type' => StockMovement::TYPE_INITIAL_STOCK,
                        'quantity_before' => 0,
                        'quantity_change' => (int) $request->quantity,
                        'quantity_after' => (int) $request->quantity,
                        'reason' => $request->reason ?? 'Initial stock created',
                        'notes' => $request->notes,
                        'movement_date' => now(),
                        'created_by' => $request->user()->id,
                        'updated_by' => $request->user()->id,
                    ]);
                }

                $stock->load('foodItem');
                $this->syncLowStockAlert($stock, $request->user()->id);

                return $stock;
            });
        } catch (\Throwable $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }

        $stock->load($this->defaultRelations());

        return $this->sendCreated(
            $this->prepareStockResponse($stock),
            'Inventory stock created successfully.'
        );
    }

    /**
     * Display one inventory stock record.
     */
    public function show(
        Request $request,
        InventoryStock $inventoryStock
    ): JsonResponse {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can view inventory stock.'
            );
        }

        $inventoryStock->load(array_merge(
            $this->defaultRelations(),
            ['stockMovements']
        ));

        return $this->sendResponse(
            $this->prepareStockResponse($inventoryStock),
            'Inventory stock retrieved successfully.'
        );
    }

    /**
     * Update an inventory stock record.
     *
     * Supports PATCH and locks the stock row before calculating changes.
     */
    public function update(
        Request $request,
        InventoryStock $inventoryStock
    ): JsonResponse {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can update inventory stock.'
            );
        }

        $validator = Validator::make($request->all(), [
            'quantity' => ['sometimes', 'required', 'integer', 'min:0'],
            'reserved_quantity' => ['sometimes', 'required', 'integer', 'min:0'],
            'low_stock_quantity' => ['sometimes', 'required', 'integer', 'min:0'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'in:active,inactive'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        try {
            $inventoryStock = DB::transaction(function () use ($request, $inventoryStock) {
                $stock = InventoryStock::query()
                    ->whereKey($inventoryStock->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $oldQuantity = (int) $stock->quantity;
                $newQuantity = $request->has('quantity')
                    ? (int) $request->quantity
                    : $oldQuantity;

                $newReserved = $request->has('reserved_quantity')
                    ? (int) $request->reserved_quantity
                    : (int) $stock->reserved_quantity;

                if ($newReserved > $newQuantity) {
                    throw new \RuntimeException(
                        'Reserved quantity cannot exceed total quantity.'
                    );
                }

                $quantityChange = $newQuantity - $oldQuantity;

                $data = [
                    'quantity' => $newQuantity,
                    'reserved_quantity' => $newReserved,
                    'updated_by' => $request->user()->id,
                ];

                foreach ([
                    'low_stock_quantity',
                    'location',
                    'status',
                ] as $field) {
                    if ($request->has($field)) {
                        $data[$field] = $request->input($field);
                    }
                }

                if ($quantityChange > 0) {
                    $data['last_restocked_at'] = now();
                }

                $stock->update($data);

                if ($quantityChange !== 0) {
                    StockMovement::create([
                        'inventory_stock_id' => $stock->id,
                        'food_item_id' => $stock->food_item_id,
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

                $stock->load('foodItem');
                $this->syncLowStockAlert($stock, $request->user()->id);

                return $stock;
            });
        } catch (\Throwable $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }

        $inventoryStock->load($this->defaultRelations());

        return $this->sendResponse(
            $this->prepareStockResponse($inventoryStock),
            'Inventory stock updated successfully.'
        );
    }

    /**
     * Delete an empty inventory stock record.
     */
    public function destroy(
        Request $request,
        InventoryStock $inventoryStock
    ): JsonResponse {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can delete inventory stock.'
            );
        }

        if (
            (int) $inventoryStock->quantity > 0 ||
            (int) $inventoryStock->reserved_quantity > 0
        ) {
            return $this->sendError(
                'Only an empty stock record can be deleted.',
                [],
                400
            );
        }

        $inventoryStock->delete();

        return $this->sendResponse(
            [],
            'Inventory stock deleted successfully.'
        );
    }

    /**
     * Restore deleted inventory stock.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can restore inventory stock.'
            );
        }

        $stock = InventoryStock::onlyTrashed()->find($id);

        if (!$stock) {
            return $this->sendNotFound(
                'Deleted inventory stock not found.'
            );
        }

        $stock->restore();
        $stock->load('foodItem');
        $this->syncLowStockAlert($stock, $request->user()->id);
        $stock->load($this->defaultRelations());

        return $this->sendResponse(
            $this->prepareStockResponse($stock),
            'Inventory stock restored successfully.'
        );
    }

    /**
     * Add stock quantity.
     */
    public function addStock(
        Request $request,
        InventoryStock $inventoryStock
    ): JsonResponse {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can add stock.'
            );
        }

        $validator = Validator::make($request->all(), [
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'reference_number' => ['nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        try {
            $inventoryStock = DB::transaction(function () use ($request, $inventoryStock) {
                $stock = InventoryStock::query()
                    ->whereKey($inventoryStock->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $quantityBefore = (int) $stock->quantity;
                $quantityAdded = (int) $request->quantity;
                $quantityAfter = $quantityBefore + $quantityAdded;

                $stock->update([
                    'quantity' => $quantityAfter,
                    'last_restocked_at' => now(),
                    'updated_by' => $request->user()->id,
                ]);

                StockMovement::create([
                    'inventory_stock_id' => $stock->id,
                    'food_item_id' => $stock->food_item_id,
                    'movement_type' => StockMovement::TYPE_RESTOCK,
                    'quantity_before' => $quantityBefore,
                    'quantity_change' => $quantityAdded,
                    'quantity_after' => $quantityAfter,
                    'unit_cost' => $request->unit_cost,
                    'total_cost' => $request->unit_cost !== null
                        ? (float) $request->unit_cost * $quantityAdded
                        : null,
                    'reference_number' => $request->reference_number,
                    'reason' => $request->reason ?? 'Stock added',
                    'notes' => $request->notes,
                    'movement_date' => now(),
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);

                $stock->load('foodItem');
                $this->syncLowStockAlert($stock, $request->user()->id);

                return $stock;
            });
        } catch (\Throwable $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }

        $inventoryStock->load($this->defaultRelations());

        return $this->sendResponse(
            $this->prepareStockResponse($inventoryStock),
            'Stock added successfully.'
        );
    }

    /**
     * Reduce sellable stock quantity without consuming reserved stock.
     */
    public function reduceStock(
        Request $request,
        InventoryStock $inventoryStock
    ): JsonResponse {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can reduce stock.'
            );
        }

        $validator = Validator::make($request->all(), [
            'quantity' => ['required', 'integer', 'min:1'],
            'movement_type' => [
                'nullable',
                'in:adjustment,damaged,expired,sale',
            ],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'reference_number' => ['nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        try {
            $inventoryStock = DB::transaction(function () use ($request, $inventoryStock) {
                $stock = InventoryStock::query()
                    ->whereKey($inventoryStock->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $availableQuantity = $this->availableQuantity($stock);
                $requestedQuantity = (int) $request->quantity;

                if ($requestedQuantity > $availableQuantity) {
                    throw new \RuntimeException(
                        'Not enough available stock. Available quantity: ' .
                        $availableQuantity . '.'
                    );
                }

                $quantityBefore = (int) $stock->quantity;
                $quantityAfter = $quantityBefore - $requestedQuantity;

                $stock->update([
                    'quantity' => $quantityAfter,
                    'updated_by' => $request->user()->id,
                ]);

                StockMovement::create([
                    'inventory_stock_id' => $stock->id,
                    'food_item_id' => $stock->food_item_id,
                    'movement_type' => $request->movement_type
                        ?? StockMovement::TYPE_ADJUSTMENT,
                    'quantity_before' => $quantityBefore,
                    'quantity_change' => -abs($requestedQuantity),
                    'quantity_after' => $quantityAfter,
                    'reference_number' => $request->reference_number,
                    'reason' => $request->reason ?? 'Stock reduced',
                    'notes' => $request->notes,
                    'movement_date' => now(),
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);

                $stock->load('foodItem');
                $this->syncLowStockAlert($stock, $request->user()->id);

                return $stock;
            });
        } catch (\Throwable $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }

        $inventoryStock->load($this->defaultRelations());

        return $this->sendResponse(
            $this->prepareStockResponse($inventoryStock),
            'Stock reduced successfully.'
        );
    }

    /**
     * Display low-stock items using available stock.
     */
    public function lowStock(Request $request): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can view low-stock items.'
            );
        }

        $query = InventoryStock::query()
            ->with($this->defaultRelations())
            ->whereRaw(
                '(quantity - COALESCE(reserved_quantity, 0)) <= low_stock_quantity'
            )
            ->orderByRaw(
                '(quantity - COALESCE(reserved_quantity, 0)) ASC'
            );

        $perPage = min(max((int) $request->get('per_page', 20), 1), 200);
        $stocks = $query->paginate($perPage);

        $stocks->getCollection()->transform(
            fn (InventoryStock $stock) => $this->prepareStockResponse($stock)
        );

        return $this->sendResponse(
            $stocks,
            'Low-stock items retrieved successfully.'
        );
    }

    /**
     * Default stock relations.
     */
    private function defaultRelations(): array
    {
        return [
            'foodItem:id,food_category_id,name,slug,sku,price,cost_price,unit,status,is_available,low_stock_quantity',
            'foodItem.category:id,name,slug,status',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ];
    }

    /**
     * Add computed available and low-stock fields.
     */
    private function prepareStockResponse(
        InventoryStock $stock
    ): InventoryStock {
        $available = $this->availableQuantity($stock);
        $threshold = $this->thresholdQuantity($stock);

        $stock->setAttribute('available_quantity', $available);
        $stock->setAttribute('is_low_stock', $available <= $threshold);
        $stock->setAttribute('is_out_of_stock', $available <= 0);

        return $stock;
    }

    /**
     * Calculate stock available for sale.
     */
    private function availableQuantity(InventoryStock $stock): int
    {
        return max(
            0,
            (int) $stock->quantity - (int) $stock->reserved_quantity
        );
    }

    /**
     * Resolve the low-stock threshold.
     */
    private function thresholdQuantity(InventoryStock $stock): int
    {
        if (
            $stock->low_stock_quantity !== null &&
            (int) $stock->low_stock_quantity >= 0
        ) {
            return (int) $stock->low_stock_quantity;
        }

        if (
            $stock->foodItem &&
            $stock->foodItem->low_stock_quantity !== null
        ) {
            return (int) $stock->foodItem->low_stock_quantity;
        }

        return 5;
    }

    /**
     * Create, refresh, or resolve the active low-stock alert.
     */
    private function syncLowStockAlert(
        InventoryStock $stock,
        int $userId
    ): void {
        if (!$stock->relationLoaded('foodItem')) {
            $stock->load('foodItem');
        }

        $available = $this->availableQuantity($stock);
        $threshold = $this->thresholdQuantity($stock);

        $activeAlert = LowStockAlert::query()
            ->where('inventory_stock_id', $stock->id)
            ->where('status', LowStockAlert::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();

        if (
            $stock->status === InventoryStock::STATUS_ACTIVE &&
            $available <= $threshold
        ) {
            $alertType = $available <= 0
                ? LowStockAlert::TYPE_OUT_OF_STOCK
                : LowStockAlert::TYPE_LOW_STOCK;

            $severity = $this->determineSeverity($available, $threshold);
            $message = $this->buildAlertMessage(
                $stock,
                $available,
                $threshold
            );

            if ($activeAlert) {
                $activeAlert->update([
                    'alert_type' => $alertType,
                    'severity' => $severity,
                    'current_quantity' => $available,
                    'threshold_quantity' => $threshold,
                    'message' => $message,
                    'updated_by' => $userId,
                ]);
            } else {
                LowStockAlert::create([
                    'inventory_stock_id' => $stock->id,
                    'food_item_id' => $stock->food_item_id,
                    'alert_number' => $this->generateAlertNumber(),
                    'alert_type' => $alertType,
                    'severity' => $severity,
                    'current_quantity' => $available,
                    'threshold_quantity' => $threshold,
                    'status' => LowStockAlert::STATUS_ACTIVE,
                    'message' => $message,
                    'notes' => 'Generated automatically after a stock change.',
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);
            }

            return;
        }

        if ($activeAlert) {
            $activeAlert->update([
                'status' => LowStockAlert::STATUS_RESOLVED,
                'resolved_by' => $userId,
                'resolved_at' => now(),
                'resolution_notes' => 'Auto-resolved because available stock is now above the threshold or the stock record is inactive.',
                'updated_by' => $userId,
            ]);
        }
    }

    private function determineSeverity(int $available, int $threshold): string
    {
        if ($available <= 0) {
            return LowStockAlert::SEVERITY_CRITICAL;
        }

        if ($available <= max(1, (int) floor($threshold / 2))) {
            return LowStockAlert::SEVERITY_HIGH;
        }

        if ($available <= $threshold) {
            return LowStockAlert::SEVERITY_MEDIUM;
        }

        return LowStockAlert::SEVERITY_LOW;
    }

    private function buildAlertMessage(
        InventoryStock $stock,
        int $available,
        int $threshold
    ): string {
        $foodName = $stock->foodItem?->name ?? 'Food item';

        if ($available <= 0) {
            return $foodName . ' is out of stock. Available quantity is 0.';
        }

        return $foodName . ' is low in stock. Available quantity is ' .
            $available . ', threshold is ' . $threshold . '.';
    }

    private function generateAlertNumber(): string
    {
        do {
            $number = 'LSA-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
        } while (LowStockAlert::where('alert_number', $number)->exists());

        return $number;
    }
}