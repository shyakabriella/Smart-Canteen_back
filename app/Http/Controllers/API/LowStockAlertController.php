<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\InventoryStock;
use App\Models\LowStockAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LowStockAlertController extends BaseController
{
    /**
     * Display low stock alerts.
     */
    public function index(Request $request): JsonResponse
    {
        $query = LowStockAlert::query()
            ->with([
                'inventoryStock:id,food_item_id,quantity,reserved_quantity,low_stock_quantity,location,status',
                'foodItem:id,food_category_id,name,sku,price,unit,status,is_available',
                'foodItem.category:id,name',
                'resolvedBy:id,name,email,phone,role',
                'dismissedBy:id,name,email,phone,role',
                'createdBy:id,name,email,phone,role',
                'updatedBy:id,name,email,phone,role',
            ])
            ->orderByRaw("
                CASE severity
                    WHEN 'critical' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'medium' THEN 3
                    WHEN 'low' THEN 4
                    ELSE 5
                END
            ")
            ->orderByDesc('id');

        if ($request->filled('food_item_id')) {
            $query->where('food_item_id', $request->food_item_id);
        }

        if ($request->filled('inventory_stock_id')) {
            $query->where('inventory_stock_id', $request->inventory_stock_id);
        }

        if ($request->filled('alert_type')) {
            $query->where('alert_type', $request->alert_type);
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('alert_number', 'like', '%' . $request->search . '%')
                    ->orWhere('message', 'like', '%' . $request->search . '%')
                    ->orWhereHas('foodItem', function ($foodQuery) use ($request) {
                        $foodQuery->where('name', 'like', '%' . $request->search . '%')
                            ->orWhere('sku', 'like', '%' . $request->search . '%');
                    });
            });
        }

        $perPage = $request->get('per_page', 20);

        $alerts = $query->paginate($perPage);

        return $this->sendResponse($alerts, 'Low stock alerts retrieved successfully.');
    }

    /**
     * Store manual low stock alert.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can create low stock alerts.');
        }

        $validator = Validator::make($request->all(), [
            'inventory_stock_id' => 'required|exists:inventory_stocks,id',
            'alert_type' => 'nullable|in:low_stock,out_of_stock,restock_required',
            'severity' => 'nullable|in:low,medium,high,critical',
            'message' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $stock = InventoryStock::with('foodItem')->findOrFail($request->inventory_stock_id);

        $activeExists = LowStockAlert::where('inventory_stock_id', $stock->id)
            ->where('status', LowStockAlert::STATUS_ACTIVE)
            ->exists();

        if ($activeExists) {
            return $this->sendError('There is already an active alert for this stock item.', [], 400);
        }

        $threshold = $this->getThresholdQuantity($stock);
        $alertType = $request->alert_type ?? $this->determineAlertType($stock->quantity);
        $severity = $request->severity ?? $this->determineSeverity($stock->quantity, $threshold);

        $alert = LowStockAlert::create([
            'inventory_stock_id' => $stock->id,
            'food_item_id' => $stock->food_item_id,
            'alert_number' => $this->generateAlertNumber(),
            'alert_type' => $alertType,
            'severity' => $severity,
            'current_quantity' => $stock->quantity,
            'threshold_quantity' => $threshold,
            'status' => LowStockAlert::STATUS_ACTIVE,
            'message' => $request->message ?? $this->buildAlertMessage($stock, $alertType, $threshold),
            'notes' => $request->notes,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $alert->load([
            'inventoryStock:id,food_item_id,quantity,low_stock_quantity,location,status',
            'foodItem:id,name,sku,price,unit,status,is_available',
            'createdBy:id,name,email,phone,role',
        ]);

        return $this->sendCreated($alert, 'Low stock alert created successfully.');
    }

    /**
     * Generate or update low stock alerts automatically from inventory.
     */
    public function generate(Request $request): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can generate low stock alerts.');
        }

        $validator = Validator::make($request->all(), [
            'auto_resolve' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $autoResolve = $request->boolean('auto_resolve', true);

        $result = DB::transaction(function () use ($request, $autoResolve) {
            $created = 0;
            $updated = 0;
            $resolved = 0;

            $stocks = InventoryStock::with('foodItem')
                ->where('status', InventoryStock::STATUS_ACTIVE)
                ->get();

            foreach ($stocks as $stock) {
                $threshold = $this->getThresholdQuantity($stock);

                if ($stock->quantity <= $threshold) {
                    $alertType = $this->determineAlertType($stock->quantity);
                    $severity = $this->determineSeverity($stock->quantity, $threshold);

                    $existingAlert = LowStockAlert::where('inventory_stock_id', $stock->id)
                        ->where('status', LowStockAlert::STATUS_ACTIVE)
                        ->first();

                    if ($existingAlert) {
                        $existingAlert->update([
                            'alert_type' => $alertType,
                            'severity' => $severity,
                            'current_quantity' => $stock->quantity,
                            'threshold_quantity' => $threshold,
                            'message' => $this->buildAlertMessage($stock, $alertType, $threshold),
                            'updated_by' => $request->user()->id,
                        ]);

                        $updated++;
                    } else {
                        LowStockAlert::create([
                            'inventory_stock_id' => $stock->id,
                            'food_item_id' => $stock->food_item_id,
                            'alert_number' => $this->generateAlertNumber(),
                            'alert_type' => $alertType,
                            'severity' => $severity,
                            'current_quantity' => $stock->quantity,
                            'threshold_quantity' => $threshold,
                            'status' => LowStockAlert::STATUS_ACTIVE,
                            'message' => $this->buildAlertMessage($stock, $alertType, $threshold),
                            'notes' => 'Generated automatically from inventory stock.',
                            'created_by' => $request->user()->id,
                            'updated_by' => $request->user()->id,
                        ]);

                        $created++;
                    }
                }
            }

            if ($autoResolve) {
                $activeAlerts = LowStockAlert::with('inventoryStock.foodItem')
                    ->where('status', LowStockAlert::STATUS_ACTIVE)
                    ->get();

                foreach ($activeAlerts as $alert) {
                    if (!$alert->inventoryStock) {
                        continue;
                    }

                    $threshold = $this->getThresholdQuantity($alert->inventoryStock);

                    if ($alert->inventoryStock->quantity > $threshold) {
                        $alert->update([
                            'status' => LowStockAlert::STATUS_RESOLVED,
                            'resolved_by' => $request->user()->id,
                            'resolved_at' => now(),
                            'resolution_notes' => 'Auto-resolved because stock quantity is now above threshold.',
                            'updated_by' => $request->user()->id,
                        ]);

                        $resolved++;
                    }
                }
            }

            return [
                'created_alerts' => $created,
                'updated_alerts' => $updated,
                'resolved_alerts' => $resolved,
            ];
        });

        return $this->sendResponse($result, 'Low stock alert generation completed successfully.');
    }

    /**
     * Display one low stock alert.
     */
    public function show(LowStockAlert $lowStockAlert): JsonResponse
    {
        $lowStockAlert->load([
            'inventoryStock:id,food_item_id,quantity,reserved_quantity,low_stock_quantity,location,status,last_restocked_at',
            'foodItem:id,food_category_id,name,sku,price,unit,status,is_available',
            'foodItem.category:id,name',
            'resolvedBy:id,name,email,phone,role',
            'dismissedBy:id,name,email,phone,role',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($lowStockAlert, 'Low stock alert retrieved successfully.');
    }

    /**
     * Update alert notes, message, or severity.
     */
    public function update(Request $request, LowStockAlert $lowStockAlert): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can update low stock alerts.');
        }

        $validator = Validator::make($request->all(), [
            'severity' => 'nullable|in:low,medium,high,critical',
            'message' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $lowStockAlert->update([
            'severity' => $request->severity ?? $lowStockAlert->severity,
            'message' => $request->message ?? $lowStockAlert->message,
            'notes' => $request->notes ?? $lowStockAlert->notes,
            'updated_by' => $request->user()->id,
        ]);

        $lowStockAlert->load([
            'inventoryStock:id,food_item_id,quantity,low_stock_quantity,location,status',
            'foodItem:id,name,sku,price,unit,status,is_available',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($lowStockAlert, 'Low stock alert updated successfully.');
    }

    /**
     * Resolve low stock alert.
     */
    public function resolve(Request $request, LowStockAlert $lowStockAlert): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can resolve low stock alerts.');
        }

        if (!$lowStockAlert->isActive()) {
            return $this->sendError('Only active alerts can be resolved.', [], 400);
        }

        $validator = Validator::make($request->all(), [
            'resolution_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $lowStockAlert->update([
            'status' => LowStockAlert::STATUS_RESOLVED,
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
            'resolution_notes' => $request->resolution_notes,
            'updated_by' => $request->user()->id,
        ]);

        $lowStockAlert->load([
            'inventoryStock:id,food_item_id,quantity,low_stock_quantity,location,status',
            'foodItem:id,name,sku,price,unit,status,is_available',
            'resolvedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($lowStockAlert, 'Low stock alert resolved successfully.');
    }

    /**
     * Dismiss low stock alert.
     */
    public function dismiss(Request $request, LowStockAlert $lowStockAlert): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can dismiss low stock alerts.');
        }

        if (!$lowStockAlert->isActive()) {
            return $this->sendError('Only active alerts can be dismissed.', [], 400);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $lowStockAlert->update([
            'status' => LowStockAlert::STATUS_DISMISSED,
            'dismissed_by' => $request->user()->id,
            'dismissed_at' => now(),
            'notes' => $request->notes ?? $lowStockAlert->notes,
            'updated_by' => $request->user()->id,
        ]);

        $lowStockAlert->load([
            'inventoryStock:id,food_item_id,quantity,low_stock_quantity,location,status',
            'foodItem:id,name,sku,price,unit,status,is_available',
            'dismissedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($lowStockAlert, 'Low stock alert dismissed successfully.');
    }

    /**
     * Delete alert.
     */
    public function destroy(Request $request, LowStockAlert $lowStockAlert): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can delete low stock alerts.');
        }

        $lowStockAlert->delete();

        return $this->sendResponse([], 'Low stock alert deleted successfully.');
    }

    /**
     * Restore deleted alert.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can restore low stock alerts.');
        }

        $alert = LowStockAlert::onlyTrashed()->find($id);

        if (!$alert) {
            return $this->sendNotFound('Deleted low stock alert not found.');
        }

        $alert->restore();

        $alert->load([
            'inventoryStock:id,food_item_id,quantity,low_stock_quantity,location,status',
            'foodItem:id,name,sku,price,unit,status,is_available',
        ]);

        return $this->sendResponse($alert, 'Low stock alert restored successfully.');
    }

    /**
     * Low stock alert summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $query = LowStockAlert::query();

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $totalAlerts = (clone $query)->count();

        $activeAlerts = (clone $query)
            ->where('status', LowStockAlert::STATUS_ACTIVE)
            ->count();

        $resolvedAlerts = (clone $query)
            ->where('status', LowStockAlert::STATUS_RESOLVED)
            ->count();

        $dismissedAlerts = (clone $query)
            ->where('status', LowStockAlert::STATUS_DISMISSED)
            ->count();

        $criticalAlerts = (clone $query)
            ->where('status', LowStockAlert::STATUS_ACTIVE)
            ->where('severity', LowStockAlert::SEVERITY_CRITICAL)
            ->count();

        $byStatus = (clone $query)
            ->select('status', DB::raw('COUNT(*) as total_records'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        $bySeverity = (clone $query)
            ->select('severity', DB::raw('COUNT(*) as total_records'))
            ->groupBy('severity')
            ->orderBy('severity')
            ->get();

        $byType = (clone $query)
            ->select('alert_type', DB::raw('COUNT(*) as total_records'))
            ->groupBy('alert_type')
            ->orderBy('alert_type')
            ->get();

        return $this->sendResponse([
            'total_alerts' => $totalAlerts,
            'active_alerts' => $activeAlerts,
            'resolved_alerts' => $resolvedAlerts,
            'dismissed_alerts' => $dismissedAlerts,
            'critical_alerts' => $criticalAlerts,
            'by_status' => $byStatus,
            'by_severity' => $bySeverity,
            'by_type' => $byType,
        ], 'Low stock alert summary retrieved successfully.');
    }

    /**
     * Generate unique alert number.
     */
    private function generateAlertNumber(): string
    {
        do {
            $number = 'LSA-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
        } while (LowStockAlert::where('alert_number', $number)->exists());

        return $number;
    }

    /**
     * Get threshold quantity from stock or food item.
     */
    private function getThresholdQuantity(InventoryStock $stock): int
    {
        if ($stock->low_stock_quantity !== null && $stock->low_stock_quantity > 0) {
            return (int) $stock->low_stock_quantity;
        }

        if ($stock->foodItem && $stock->foodItem->low_stock_quantity > 0) {
            return (int) $stock->foodItem->low_stock_quantity;
        }

        return 5;
    }

    /**
     * Determine alert type.
     */
    private function determineAlertType(int $quantity): string
    {
        if ($quantity <= 0) {
            return LowStockAlert::TYPE_OUT_OF_STOCK;
        }

        return LowStockAlert::TYPE_LOW_STOCK;
    }

    /**
     * Determine severity.
     */
    private function determineSeverity(int $quantity, int $threshold): string
    {
        if ($quantity <= 0) {
            return LowStockAlert::SEVERITY_CRITICAL;
        }

        if ($quantity <= max(1, floor($threshold / 2))) {
            return LowStockAlert::SEVERITY_HIGH;
        }

        if ($quantity <= $threshold) {
            return LowStockAlert::SEVERITY_MEDIUM;
        }

        return LowStockAlert::SEVERITY_LOW;
    }

    /**
     * Build alert message.
     */
    private function buildAlertMessage(InventoryStock $stock, string $alertType, int $threshold): string
    {
        $foodName = $stock->foodItem?->name ?? 'Food item';

        if ($alertType === LowStockAlert::TYPE_OUT_OF_STOCK) {
            return $foodName . ' is out of stock. Current quantity is ' . $stock->quantity . '.';
        }

        return $foodName . ' is low in stock. Current quantity is '
            . $stock->quantity . ', threshold is ' . $threshold . '.';
    }
}