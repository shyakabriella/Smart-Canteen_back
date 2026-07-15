<?php

namespace App\Http\Controllers\API;

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
     * Display low-stock alerts.
     */
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can view low stock alerts.'
            );
        }

        $query = LowStockAlert::query()
            ->with($this->defaultRelations())
            ->orderByRaw("\n                CASE severity\n                    WHEN 'critical' THEN 1\n                    WHEN 'high' THEN 2\n                    WHEN 'medium' THEN 3\n                    WHEN 'low' THEN 4\n                    ELSE 5\n                END\n            ")
            ->orderByDesc('id');

        foreach ([
            'food_item_id',
            'inventory_stock_id',
            'alert_type',
            'severity',
            'status',
        ] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                $q->where('alert_number', 'like', '%' . $search . '%')
                    ->orWhere('message', 'like', '%' . $search . '%')
                    ->orWhereHas('foodItem', function ($foodQuery) use ($search) {
                        $foodQuery
                            ->where('name', 'like', '%' . $search . '%')
                            ->orWhere('sku', 'like', '%' . $search . '%');
                    });
            });
        }

        $perPage = min(max((int) $request->get('per_page', 20), 1), 200);
        $alerts = $query->paginate($perPage);

        return $this->sendResponse(
            $alerts,
            'Low stock alerts retrieved successfully.'
        );
    }

    /**
     * Store a manual low-stock alert.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can create low stock alerts.'
            );
        }

        $validator = Validator::make($request->all(), [
            'inventory_stock_id' => [
                'required',
                'integer',
                'exists:inventory_stocks,id',
            ],
            'alert_type' => [
                'nullable',
                'in:low_stock,out_of_stock,restock_required',
            ],
            'severity' => ['nullable', 'in:low,medium,high,critical'],
            'message' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        try {
            $alert = DB::transaction(function () use ($request) {
                $stock = InventoryStock::with('foodItem')
                    ->whereKey($request->inventory_stock_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $activeExists = LowStockAlert::query()
                    ->where('inventory_stock_id', $stock->id)
                    ->where('status', LowStockAlert::STATUS_ACTIVE)
                    ->exists();

                if ($activeExists) {
                    throw new \RuntimeException(
                        'There is already an active alert for this stock item.'
                    );
                }

                $available = $this->availableQuantity($stock);
                $threshold = $this->getThresholdQuantity($stock);

                if ($available > $threshold) {
                    throw new \RuntimeException(
                        'Available stock is above the low-stock threshold. No alert is required.'
                    );
                }

                $alertType = $request->alert_type
                    ?? $this->determineAlertType($available);

                $severity = $request->severity
                    ?? $this->determineSeverity($available, $threshold);

                return LowStockAlert::create([
                    'inventory_stock_id' => $stock->id,
                    'food_item_id' => $stock->food_item_id,
                    'alert_number' => $this->generateAlertNumber(),
                    'alert_type' => $alertType,
                    'severity' => $severity,
                    'current_quantity' => $available,
                    'threshold_quantity' => $threshold,
                    'status' => LowStockAlert::STATUS_ACTIVE,
                    'message' => $request->message
                        ?? $this->buildAlertMessage(
                            $stock,
                            $alertType,
                            $available,
                            $threshold
                        ),
                    'notes' => $request->notes,
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);
            });
        } catch (\Throwable $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }

        $alert->load($this->defaultRelations());

        return $this->sendCreated(
            $alert,
            'Low stock alert created successfully.'
        );
    }

    /**
     * Generate, refresh, and optionally auto-resolve alerts from stock.
     */
    public function generate(Request $request): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can generate low stock alerts.'
            );
        }

        $validator = Validator::make($request->all(), [
            'auto_resolve' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        $autoResolve = $request->boolean('auto_resolve', true);

        $result = DB::transaction(function () use ($request, $autoResolve) {
            $created = 0;
            $updated = 0;
            $resolved = 0;

            $stocks = InventoryStock::with('foodItem')
                ->where('status', InventoryStock::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get();

            foreach ($stocks as $stock) {
                $available = $this->availableQuantity($stock);
                $threshold = $this->getThresholdQuantity($stock);

                $existingAlert = LowStockAlert::query()
                    ->where('inventory_stock_id', $stock->id)
                    ->where('status', LowStockAlert::STATUS_ACTIVE)
                    ->lockForUpdate()
                    ->first();

                if ($available <= $threshold) {
                    $alertType = $this->determineAlertType($available);
                    $severity = $this->determineSeverity(
                        $available,
                        $threshold
                    );

                    $data = [
                        'alert_type' => $alertType,
                        'severity' => $severity,
                        'current_quantity' => $available,
                        'threshold_quantity' => $threshold,
                        'message' => $this->buildAlertMessage(
                            $stock,
                            $alertType,
                            $available,
                            $threshold
                        ),
                        'updated_by' => $request->user()->id,
                    ];

                    if ($existingAlert) {
                        $existingAlert->update($data);
                        $updated++;
                    } else {
                        LowStockAlert::create(array_merge($data, [
                            'inventory_stock_id' => $stock->id,
                            'food_item_id' => $stock->food_item_id,
                            'alert_number' => $this->generateAlertNumber(),
                            'status' => LowStockAlert::STATUS_ACTIVE,
                            'notes' => 'Generated automatically from available inventory stock.',
                            'created_by' => $request->user()->id,
                        ]));

                        $created++;
                    }
                } elseif ($autoResolve && $existingAlert) {
                    $existingAlert->update([
                        'status' => LowStockAlert::STATUS_RESOLVED,
                        'resolved_by' => $request->user()->id,
                        'resolved_at' => now(),
                        'resolution_notes' => 'Auto-resolved because available stock is now above the threshold.',
                        'updated_by' => $request->user()->id,
                    ]);

                    $resolved++;
                }
            }

            if ($autoResolve) {
                $orphanedOrInactiveAlerts = LowStockAlert::with('inventoryStock')
                    ->where('status', LowStockAlert::STATUS_ACTIVE)
                    ->get();

                foreach ($orphanedOrInactiveAlerts as $alert) {
                    if (
                        !$alert->inventoryStock ||
                        $alert->inventoryStock->status !== InventoryStock::STATUS_ACTIVE
                    ) {
                        $alert->update([
                            'status' => LowStockAlert::STATUS_RESOLVED,
                            'resolved_by' => $request->user()->id,
                            'resolved_at' => now(),
                            'resolution_notes' => 'Auto-resolved because the stock record is missing or inactive.',
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

        return $this->sendResponse(
            $result,
            'Low stock alert generation completed successfully.'
        );
    }

    /**
     * Display one low-stock alert.
     */
    public function show(
        Request $request,
        LowStockAlert $lowStockAlert
    ): JsonResponse {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can view low stock alerts.'
            );
        }

        $lowStockAlert->load($this->defaultRelations());

        return $this->sendResponse(
            $lowStockAlert,
            'Low stock alert retrieved successfully.'
        );
    }

    /**
     * Update an active alert.
     */
    public function update(
        Request $request,
        LowStockAlert $lowStockAlert
    ): JsonResponse {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can update low stock alerts.'
            );
        }

        if (!$lowStockAlert->isActive()) {
            return $this->sendError(
                'Only active alerts can be updated.',
                [],
                400
            );
        }

        $validator = Validator::make($request->all(), [
            'severity' => [
                'sometimes',
                'required',
                'in:low,medium,high,critical',
            ],
            'message' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        $data = ['updated_by' => $request->user()->id];

        foreach (['severity', 'message', 'notes'] as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        $lowStockAlert->update($data);
        $lowStockAlert->load($this->defaultRelations());

        return $this->sendResponse(
            $lowStockAlert,
            'Low stock alert updated successfully.'
        );
    }

    /**
     * Resolve a low-stock alert.
     */
    public function resolve(
        Request $request,
        LowStockAlert $lowStockAlert
    ): JsonResponse {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can resolve low stock alerts.'
            );
        }

        if (!$lowStockAlert->isActive()) {
            return $this->sendError(
                'Only active alerts can be resolved.',
                [],
                400
            );
        }

        $validator = Validator::make($request->all(), [
            'resolution_notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        $lowStockAlert->update([
            'status' => LowStockAlert::STATUS_RESOLVED,
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
            'resolution_notes' => $request->resolution_notes,
            'updated_by' => $request->user()->id,
        ]);

        $lowStockAlert->load($this->defaultRelations());

        return $this->sendResponse(
            $lowStockAlert,
            'Low stock alert resolved successfully.'
        );
    }

    /**
     * Dismiss a low-stock alert.
     */
    public function dismiss(
        Request $request,
        LowStockAlert $lowStockAlert
    ): JsonResponse {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can dismiss low stock alerts.'
            );
        }

        if (!$lowStockAlert->isActive()) {
            return $this->sendError(
                'Only active alerts can be dismissed.',
                [],
                400
            );
        }

        $validator = Validator::make($request->all(), [
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        $lowStockAlert->update([
            'status' => LowStockAlert::STATUS_DISMISSED,
            'dismissed_by' => $request->user()->id,
            'dismissed_at' => now(),
            'notes' => $request->notes ?? $lowStockAlert->notes,
            'updated_by' => $request->user()->id,
        ]);

        $lowStockAlert->load($this->defaultRelations());

        return $this->sendResponse(
            $lowStockAlert,
            'Low stock alert dismissed successfully.'
        );
    }

    /**
     * Delete a non-active alert.
     */
    public function destroy(
        Request $request,
        LowStockAlert $lowStockAlert
    ): JsonResponse {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can delete low stock alerts.'
            );
        }

        if ($lowStockAlert->isActive()) {
            return $this->sendError(
                'Resolve or dismiss the alert before deleting it.',
                [],
                400
            );
        }

        $lowStockAlert->delete();

        return $this->sendResponse(
            [],
            'Low stock alert deleted successfully.'
        );
    }

    /**
     * Restore a deleted alert.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can restore low stock alerts.'
            );
        }

        $alert = LowStockAlert::onlyTrashed()->find($id);

        if (!$alert) {
            return $this->sendNotFound(
                'Deleted low stock alert not found.'
            );
        }

        $alert->restore();
        $alert->load($this->defaultRelations());

        return $this->sendResponse(
            $alert,
            'Low stock alert restored successfully.'
        );
    }

    /**
     * Low-stock alert summary.
     */
    public function summary(Request $request): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can view low stock alert summary.'
            );
        }

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

    private function defaultRelations(): array
    {
        return [
            'inventoryStock:id,food_item_id,quantity,reserved_quantity,low_stock_quantity,location,status,last_restocked_at',
            'foodItem:id,food_category_id,name,sku,price,unit,status,is_available',
            'foodItem.category:id,name',
            'resolvedBy:id,name,email,phone,role',
            'dismissedBy:id,name,email,phone,role',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ];
    }

    private function availableQuantity(InventoryStock $stock): int
    {
        return max(
            0,
            (int) $stock->quantity - (int) $stock->reserved_quantity
        );
    }

    private function generateAlertNumber(): string
    {
        do {
            $number = 'LSA-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
        } while (LowStockAlert::where('alert_number', $number)->exists());

        return $number;
    }

    private function getThresholdQuantity(InventoryStock $stock): int
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

    private function determineAlertType(int $available): string
    {
        return $available <= 0
            ? LowStockAlert::TYPE_OUT_OF_STOCK
            : LowStockAlert::TYPE_LOW_STOCK;
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
        string $alertType,
        int $available,
        int $threshold
    ): string {
        $foodName = $stock->foodItem?->name ?? 'Food item';

        if ($alertType === LowStockAlert::TYPE_OUT_OF_STOCK) {
            return $foodName . ' is out of stock. Available quantity is 0.';
        }

        return $foodName . ' is low in stock. Available quantity is ' .
            $available . ', threshold is ' . $threshold . '.';
    }
}