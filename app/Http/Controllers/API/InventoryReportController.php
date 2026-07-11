<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\FoodItem;
use App\Models\InventoryReport;
use App\Models\InventoryStock;
use App\Models\LowStockAlert;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class InventoryReportController extends BaseController
{
    /**
     * Display inventory reports.
     */
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can view inventory reports.');
        }

        $query = InventoryReport::query()
            ->with($this->defaultRelations())
            ->orderByDesc('id');

        if ($request->filled('report_type')) {
            $query->where('report_type', $request->report_type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('generated_by')) {
            $query->where('generated_by', $request->generated_by);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('period_start', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('period_end', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('report_number', 'like', '%' . $request->search . '%')
                    ->orWhere('title', 'like', '%' . $request->search . '%')
                    ->orWhere('notes', 'like', '%' . $request->search . '%');
            });
        }

        $perPage = $request->get('per_page', 20);

        $reports = $query->paginate($perPage);

        return $this->sendResponse($reports, 'Inventory reports retrieved successfully.');
    }

    /**
     * Store/generate inventory report.
     */
    public function store(Request $request): JsonResponse
    {
        return $this->generate($request);
    }

    /**
     * Generate inventory report.
     */
    public function generate(Request $request): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can generate inventory reports.');
        }

        $validator = Validator::make($request->all(), [
            'report_type' => 'nullable|in:current_stock,daily,weekly,monthly,custom',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'title' => 'nullable|string|max:255',
            'status' => 'nullable|in:draft,final',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $reportType = $request->report_type ?? InventoryReport::TYPE_CURRENT_STOCK;

        $data = $this->buildReportData(
            $request->period_start,
            $request->period_end
        );

        $report = InventoryReport::create([
            'report_number' => $this->generateReportNumber(),
            'title' => $request->title ?? $this->buildReportTitle(
                $reportType,
                $request->period_start,
                $request->period_end
            ),
            'report_type' => $reportType,
            'period_start' => $request->period_start,
            'period_end' => $request->period_end,

            'total_food_items' => $data['total_food_items'],
            'active_food_items' => $data['active_food_items'],
            'inactive_food_items' => $data['inactive_food_items'],
            'available_food_items' => $data['available_food_items'],
            'unavailable_food_items' => $data['unavailable_food_items'],

            'total_stock_records' => $data['total_stock_records'],
            'total_stock_quantity' => $data['total_stock_quantity'],
            'total_reserved_quantity' => $data['total_reserved_quantity'],
            'total_available_quantity' => $data['total_available_quantity'],

            'low_stock_items' => $data['low_stock_items'],
            'out_of_stock_items' => $data['out_of_stock_items'],

            'total_stock_cost_value' => $data['total_stock_cost_value'],
            'total_stock_retail_value' => $data['total_stock_retail_value'],

            'total_movements' => $data['total_movements'],
            'restock_quantity' => $data['restock_quantity'],
            'sales_quantity' => $data['sales_quantity'],
            'adjustment_quantity' => $data['adjustment_quantity'],
            'damaged_quantity' => $data['damaged_quantity'],
            'expired_quantity' => $data['expired_quantity'],
            'return_quantity' => $data['return_quantity'],

            'status' => $request->status ?? InventoryReport::STATUS_DRAFT,
            'report_data' => $data['report_data'],
            'notes' => $request->notes,

            'generated_by' => $request->user()->id,
            'generated_at' => now(),

            'finalized_by' => ($request->status === InventoryReport::STATUS_FINAL)
                ? $request->user()->id
                : null,

            'finalized_at' => ($request->status === InventoryReport::STATUS_FINAL)
                ? now()
                : null,

            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $report->load($this->defaultRelations());

        return $this->sendCreated($report, 'Inventory report generated successfully.');
    }

    /**
     * Display one inventory report.
     */
    public function show(Request $request, InventoryReport $inventoryReport): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can view inventory reports.');
        }

        $inventoryReport->load($this->defaultRelations());

        return $this->sendResponse($inventoryReport, 'Inventory report retrieved successfully.');
    }

    /**
     * Update inventory report title/notes/status.
     */
    public function update(Request $request, InventoryReport $inventoryReport): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can update inventory reports.');
        }

        if ($inventoryReport->isFinal()) {
            return $this->sendError('Finalized reports cannot be updated. Regenerate a new report instead.', [], 400);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:draft,cancelled',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $inventoryReport->update([
            'title' => $request->title ?? $inventoryReport->title,
            'notes' => $request->notes ?? $inventoryReport->notes,
            'status' => $request->status ?? $inventoryReport->status,
            'updated_by' => $request->user()->id,
        ]);

        $inventoryReport->load($this->defaultRelations());

        return $this->sendResponse($inventoryReport, 'Inventory report updated successfully.');
    }

    /**
     * Regenerate existing inventory report using same period.
     */
    public function regenerate(Request $request, InventoryReport $inventoryReport): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can regenerate inventory reports.');
        }

        if ($inventoryReport->isFinal()) {
            return $this->sendError('Finalized reports cannot be regenerated.', [], 400);
        }

        $data = $this->buildReportData(
            $inventoryReport->period_start->format('Y-m-d'),
            $inventoryReport->period_end->format('Y-m-d')
        );

        $inventoryReport->update([
            'total_food_items' => $data['total_food_items'],
            'active_food_items' => $data['active_food_items'],
            'inactive_food_items' => $data['inactive_food_items'],
            'available_food_items' => $data['available_food_items'],
            'unavailable_food_items' => $data['unavailable_food_items'],

            'total_stock_records' => $data['total_stock_records'],
            'total_stock_quantity' => $data['total_stock_quantity'],
            'total_reserved_quantity' => $data['total_reserved_quantity'],
            'total_available_quantity' => $data['total_available_quantity'],

            'low_stock_items' => $data['low_stock_items'],
            'out_of_stock_items' => $data['out_of_stock_items'],

            'total_stock_cost_value' => $data['total_stock_cost_value'],
            'total_stock_retail_value' => $data['total_stock_retail_value'],

            'total_movements' => $data['total_movements'],
            'restock_quantity' => $data['restock_quantity'],
            'sales_quantity' => $data['sales_quantity'],
            'adjustment_quantity' => $data['adjustment_quantity'],
            'damaged_quantity' => $data['damaged_quantity'],
            'expired_quantity' => $data['expired_quantity'],
            'return_quantity' => $data['return_quantity'],

            'report_data' => $data['report_data'],
            'generated_by' => $request->user()->id,
            'generated_at' => now(),
            'updated_by' => $request->user()->id,
        ]);

        $inventoryReport->load($this->defaultRelations());

        return $this->sendResponse($inventoryReport, 'Inventory report regenerated successfully.');
    }

    /**
     * Finalize report.
     */
    public function finalize(Request $request, InventoryReport $inventoryReport): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can finalize inventory reports.');
        }

        if ($inventoryReport->isFinal()) {
            return $this->sendError('Inventory report is already finalized.', [], 400);
        }

        if ($inventoryReport->isCancelled()) {
            return $this->sendError('Cancelled inventory report cannot be finalized.', [], 400);
        }

        $inventoryReport->update([
            'status' => InventoryReport::STATUS_FINAL,
            'finalized_by' => $request->user()->id,
            'finalized_at' => now(),
            'updated_by' => $request->user()->id,
        ]);

        $inventoryReport->load($this->defaultRelations());

        return $this->sendResponse($inventoryReport, 'Inventory report finalized successfully.');
    }

    /**
     * Delete inventory report.
     */
    public function destroy(Request $request, InventoryReport $inventoryReport): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can delete inventory reports.');
        }

        if ($inventoryReport->isFinal()) {
            return $this->sendError('Finalized inventory reports cannot be deleted.', [], 400);
        }

        $inventoryReport->delete();

        return $this->sendResponse([], 'Inventory report deleted successfully.');
    }

    /**
     * Restore deleted inventory report.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can restore inventory reports.');
        }

        $report = InventoryReport::onlyTrashed()->find($id);

        if (!$report) {
            return $this->sendNotFound('Deleted inventory report not found.');
        }

        $report->restore();

        $report->load($this->defaultRelations());

        return $this->sendResponse($report, 'Inventory report restored successfully.');
    }

    /**
     * Inventory report summary.
     */
    public function summary(Request $request): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can view inventory report summary.');
        }

        $query = InventoryReport::query();

        if ($request->filled('from_date')) {
            $query->whereDate('period_start', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('period_end', '<=', $request->to_date);
        }

        if ($request->filled('report_type')) {
            $query->where('report_type', $request->report_type);
        }

        $totalReports = (clone $query)->count();

        $draftReports = (clone $query)
            ->where('status', InventoryReport::STATUS_DRAFT)
            ->count();

        $finalReports = (clone $query)
            ->where('status', InventoryReport::STATUS_FINAL)
            ->count();

        $latestReport = (clone $query)
            ->orderByDesc('id')
            ->first();

        $byType = (clone $query)
            ->select('report_type', DB::raw('COUNT(*) as total_records'))
            ->groupBy('report_type')
            ->orderBy('report_type')
            ->get();

        $byStatus = (clone $query)
            ->select('status', DB::raw('COUNT(*) as total_records'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        return $this->sendResponse([
            'total_reports' => $totalReports,
            'draft_reports' => $draftReports,
            'final_reports' => $finalReports,
            'latest_report' => $latestReport,
            'by_type' => $byType,
            'by_status' => $byStatus,
        ], 'Inventory report summary retrieved successfully.');
    }

    /**
     * Build report data from inventory and stock movements.
     */
    private function buildReportData(string $periodStart, string $periodEnd): array
    {
        $stocks = InventoryStock::with([
                'foodItem:id,food_category_id,name,sku,price,cost_price,unit,status,is_available,low_stock_quantity',
                'foodItem.category:id,name',
            ])
            ->get();

        $totalFoodItems = FoodItem::count();

        $activeFoodItems = FoodItem::where('status', FoodItem::STATUS_ACTIVE)->count();

        $inactiveFoodItems = FoodItem::where('status', FoodItem::STATUS_INACTIVE)->count();

        $availableFoodItems = FoodItem::where('is_available', true)->count();

        $unavailableFoodItems = FoodItem::where('is_available', false)->count();

        $totalStockRecords = $stocks->count();

        $totalStockQuantity = (int) $stocks->sum('quantity');

        $totalReservedQuantity = (int) $stocks->sum('reserved_quantity');

        $totalAvailableQuantity = (int) $stocks->sum(function ($stock) {
            return max(0, (int) $stock->quantity - (int) $stock->reserved_quantity);
        });

        $lowStockItems = 0;
        $outOfStockItems = 0;
        $totalStockCostValue = 0;
        $totalStockRetailValue = 0;
        $lowStockList = [];
        $outOfStockList = [];
        $stockValueList = [];

        foreach ($stocks as $stock) {
            $quantity = (int) $stock->quantity;
            $reserved = (int) $stock->reserved_quantity;
            $available = max(0, $quantity - $reserved);
            $threshold = $this->getThresholdQuantity($stock);

            $costPrice = $stock->foodItem && $stock->foodItem->cost_price !== null
                ? (float) $stock->foodItem->cost_price
                : 0;

            $retailPrice = $stock->foodItem && $stock->foodItem->price !== null
                ? (float) $stock->foodItem->price
                : 0;

            $costValue = $quantity * $costPrice;
            $retailValue = $quantity * $retailPrice;

            $totalStockCostValue += $costValue;
            $totalStockRetailValue += $retailValue;

            $stockValueList[] = [
                'food_item_id' => $stock->food_item_id,
                'food_name' => $stock->foodItem?->name,
                'sku' => $stock->foodItem?->sku,
                'category' => $stock->foodItem?->category?->name,
                'quantity' => $quantity,
                'reserved_quantity' => $reserved,
                'available_quantity' => $available,
                'unit' => $stock->foodItem?->unit,
                'cost_price' => $costPrice,
                'retail_price' => $retailPrice,
                'cost_value' => $costValue,
                'retail_value' => $retailValue,
                'threshold_quantity' => $threshold,
                'location' => $stock->location,
            ];

            if ($quantity <= 0) {
                $outOfStockItems++;

                $outOfStockList[] = [
                    'food_item_id' => $stock->food_item_id,
                    'food_name' => $stock->foodItem?->name,
                    'sku' => $stock->foodItem?->sku,
                    'quantity' => $quantity,
                    'threshold_quantity' => $threshold,
                    'location' => $stock->location,
                ];
            } elseif ($quantity <= $threshold) {
                $lowStockItems++;

                $lowStockList[] = [
                    'food_item_id' => $stock->food_item_id,
                    'food_name' => $stock->foodItem?->name,
                    'sku' => $stock->foodItem?->sku,
                    'quantity' => $quantity,
                    'threshold_quantity' => $threshold,
                    'location' => $stock->location,
                ];
            }
        }

        $movementQuery = StockMovement::query()
            ->whereDate('movement_date', '>=', $periodStart)
            ->whereDate('movement_date', '<=', $periodEnd);

        $totalMovements = (clone $movementQuery)->count();

        $restockQuantity = $this->sumMovementQuantity($movementQuery, StockMovement::TYPE_RESTOCK, true);

        $salesQuantity = $this->sumMovementQuantity($movementQuery, StockMovement::TYPE_SALE, false);

        $adjustmentQuantity = $this->sumMovementQuantity($movementQuery, StockMovement::TYPE_ADJUSTMENT, false);

        $damagedQuantity = $this->sumMovementQuantity($movementQuery, StockMovement::TYPE_DAMAGED, false);

        $expiredQuantity = $this->sumMovementQuantity($movementQuery, StockMovement::TYPE_EXPIRED, false);

        $returnQuantity = $this->sumMovementQuantity($movementQuery, StockMovement::TYPE_RETURN, true);

        $movementsByType = (clone $movementQuery)
            ->select(
                'movement_type',
                DB::raw('COUNT(*) as total_records'),
                DB::raw('SUM(quantity_change) as total_quantity_change')
            )
            ->groupBy('movement_type')
            ->orderBy('movement_type')
            ->get();

        $topRestockedItems = StockMovement::query()
            ->select(
                'food_item_id',
                DB::raw('SUM(quantity_change) as total_quantity')
            )
            ->with('foodItem:id,name,sku,unit')
            ->whereDate('movement_date', '>=', $periodStart)
            ->whereDate('movement_date', '<=', $periodEnd)
            ->where('movement_type', StockMovement::TYPE_RESTOCK)
            ->groupBy('food_item_id')
            ->orderByDesc('total_quantity')
            ->limit(10)
            ->get();

        $topSoldItems = StockMovement::query()
            ->select(
                'food_item_id',
                DB::raw('ABS(SUM(quantity_change)) as total_quantity')
            )
            ->with('foodItem:id,name,sku,unit')
            ->whereDate('movement_date', '>=', $periodStart)
            ->whereDate('movement_date', '<=', $periodEnd)
            ->where('movement_type', StockMovement::TYPE_SALE)
            ->groupBy('food_item_id')
            ->orderByDesc('total_quantity')
            ->limit(10)
            ->get();

        $activeLowStockAlerts = LowStockAlert::with([
                'foodItem:id,name,sku,unit',
            ])
            ->where('status', LowStockAlert::STATUS_ACTIVE)
            ->orderByRaw("
                CASE severity
                    WHEN 'critical' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'medium' THEN 3
                    WHEN 'low' THEN 4
                    ELSE 5
                END
            ")
            ->limit(20)
            ->get();

        return [
            'total_food_items' => $totalFoodItems,
            'active_food_items' => $activeFoodItems,
            'inactive_food_items' => $inactiveFoodItems,
            'available_food_items' => $availableFoodItems,
            'unavailable_food_items' => $unavailableFoodItems,

            'total_stock_records' => $totalStockRecords,
            'total_stock_quantity' => $totalStockQuantity,
            'total_reserved_quantity' => $totalReservedQuantity,
            'total_available_quantity' => $totalAvailableQuantity,

            'low_stock_items' => $lowStockItems,
            'out_of_stock_items' => $outOfStockItems,

            'total_stock_cost_value' => $totalStockCostValue,
            'total_stock_retail_value' => $totalStockRetailValue,

            'total_movements' => $totalMovements,
            'restock_quantity' => $restockQuantity,
            'sales_quantity' => $salesQuantity,
            'adjustment_quantity' => $adjustmentQuantity,
            'damaged_quantity' => $damagedQuantity,
            'expired_quantity' => $expiredQuantity,
            'return_quantity' => $returnQuantity,

            'report_data' => [
                'low_stock_items' => $lowStockList,
                'out_of_stock_items' => $outOfStockList,
                'stock_values' => $stockValueList,
                'movements_by_type' => $movementsByType,
                'top_restocked_items' => $topRestockedItems,
                'top_sold_items' => $topSoldItems,
                'active_low_stock_alerts' => $activeLowStockAlerts,
            ],
        ];
    }

    /**
     * Sum movement quantity.
     */
    private function sumMovementQuantity($baseQuery, string $movementType, bool $positiveOnly = true): int
    {
        $sum = (clone $baseQuery)
            ->where('movement_type', $movementType)
            ->sum('quantity_change');

        if ($positiveOnly) {
            return (int) max(0, $sum);
        }

        return (int) abs($sum);
    }

    /**
     * Default relations.
     */
    private function defaultRelations(): array
    {
        return [
            'generatedBy:id,name,email,phone,role',
            'finalizedBy:id,name,email,phone,role',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ];
    }

    /**
     * Generate unique report number.
     */
    private function generateReportNumber(): string
    {
        do {
            $number = 'IR-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
        } while (InventoryReport::where('report_number', $number)->exists());

        return $number;
    }

    /**
     * Build default report title.
     */
    private function buildReportTitle(string $reportType, string $periodStart, string $periodEnd): string
    {
        return ucfirst(str_replace('_', ' ', $reportType)) . ' Inventory Report: ' . $periodStart . ' to ' . $periodEnd;
    }

    /**
     * Get threshold quantity.
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
}