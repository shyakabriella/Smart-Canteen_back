<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\InventoryStock;
use App\Models\LowStockAlert;
use App\Models\PurchaseRequest;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PurchaseRequestController extends BaseController
{
    /**
     * Display purchase requests.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PurchaseRequest::query()
            ->with([
                'supplier:id,supplier_code,name,contact_person,email,phone,status',
                'inventoryStock:id,food_item_id,quantity,reserved_quantity,low_stock_quantity,location,status',
                'foodItem:id,food_category_id,name,sku,price,cost_price,unit,status,is_available',
                'foodItem.category:id,name',
                'lowStockAlert:id,alert_number,alert_type,severity,status,current_quantity,threshold_quantity',
                'requestedBy:id,name,email,phone,role',
                'approvedBy:id,name,email,phone,role',
                'rejectedBy:id,name,email,phone,role',
                'orderedBy:id,name,email,phone,role',
                'receivedBy:id,name,email,phone,role',
                'cancelledBy:id,name,email,phone,role',
                'createdBy:id,name,email,phone,role',
                'updatedBy:id,name,email,phone,role',
            ])
            ->orderByDesc('id');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->filled('inventory_stock_id')) {
            $query->where('inventory_stock_id', $request->inventory_stock_id);
        }

        if ($request->filled('food_item_id')) {
            $query->where('food_item_id', $request->food_item_id);
        }

        if ($request->filled('low_stock_alert_id')) {
            $query->where('low_stock_alert_id', $request->low_stock_alert_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('requested_by')) {
            $query->where('requested_by', $request->requested_by);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('requested_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('requested_at', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('request_number', 'like', '%' . $request->search . '%')
                    ->orWhere('reason', 'like', '%' . $request->search . '%')
                    ->orWhere('supplier_reference', 'like', '%' . $request->search . '%')
                    ->orWhereHas('foodItem', function ($foodQuery) use ($request) {
                        $foodQuery->where('name', 'like', '%' . $request->search . '%')
                            ->orWhere('sku', 'like', '%' . $request->search . '%');
                    })
                    ->orWhereHas('supplier', function ($supplierQuery) use ($request) {
                        $supplierQuery->where('name', 'like', '%' . $request->search . '%')
                            ->orWhere('supplier_code', 'like', '%' . $request->search . '%')
                            ->orWhere('phone', 'like', '%' . $request->search . '%');
                    });
            });
        }

        $perPage = $request->get('per_page', 20);

        $purchaseRequests = $query->paginate($perPage);

        return $this->sendResponse($purchaseRequests, 'Purchase requests retrieved successfully.');
    }

    /**
     * Store purchase request.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can create purchase requests.');
        }

        $validator = Validator::make($request->all(), [
            'supplier_id' => 'nullable|exists:suppliers,id',
            'inventory_stock_id' => 'required|exists:inventory_stocks,id',
            'low_stock_alert_id' => 'nullable|exists:low_stock_alerts,id',
            'quantity_requested' => 'required|integer|min:1',
            'estimated_unit_cost' => 'nullable|numeric|min:0',
            'reason' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $stock = InventoryStock::with('foodItem')
            ->findOrFail($request->inventory_stock_id);

        if (!$stock->foodItem) {
            return $this->sendError('Food item not found for this stock record.', [], 400);
        }

        if ($request->filled('low_stock_alert_id')) {
            $alert = LowStockAlert::findOrFail($request->low_stock_alert_id);

            if ($alert->inventory_stock_id !== $stock->id) {
                return $this->sendError('The selected low stock alert does not match this inventory stock.', [], 400);
            }
        }

        $estimatedUnitCost = $request->estimated_unit_cost !== null
            ? (float) $request->estimated_unit_cost
            : ($stock->foodItem->cost_price !== null ? (float) $stock->foodItem->cost_price : null);

        $estimatedTotalCost = $estimatedUnitCost !== null
            ? $estimatedUnitCost * (int) $request->quantity_requested
            : null;

        $purchaseRequest = PurchaseRequest::create([
            'request_number' => $this->generateRequestNumber(),
            'supplier_id' => $request->supplier_id,
            'inventory_stock_id' => $stock->id,
            'food_item_id' => $stock->food_item_id,
            'low_stock_alert_id' => $request->low_stock_alert_id,
            'quantity_requested' => $request->quantity_requested,
            'quantity_approved' => 0,
            'quantity_received' => 0,
            'estimated_unit_cost' => $estimatedUnitCost,
            'estimated_total_cost' => $estimatedTotalCost,
            'received_unit_cost' => null,
            'received_total_cost' => 0,
            'status' => PurchaseRequest::STATUS_PENDING,
            'reason' => $request->reason ?? 'Inventory restocking request',
            'notes' => $request->notes,
            'requested_by' => $request->user()->id,
            'requested_at' => now(),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $purchaseRequest->load($this->defaultRelations());

        return $this->sendCreated($purchaseRequest, 'Purchase request created successfully.');
    }

    /**
     * Display one purchase request.
     */
    public function show(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $purchaseRequest->load($this->defaultRelations());

        return $this->sendResponse($purchaseRequest, 'Purchase request retrieved successfully.');
    }

    /**
     * Update pending purchase request.
     */
    public function update(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can update purchase requests.');
        }

        if (!$purchaseRequest->canBeEdited()) {
            return $this->sendError('Only pending purchase requests can be updated.', [], 400);
        }

        $validator = Validator::make($request->all(), [
            'supplier_id' => 'nullable|exists:suppliers,id',
            'quantity_requested' => 'required|integer|min:1',
            'estimated_unit_cost' => 'nullable|numeric|min:0',
            'reason' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $estimatedUnitCost = $request->estimated_unit_cost !== null
            ? (float) $request->estimated_unit_cost
            : null;

        $estimatedTotalCost = $estimatedUnitCost !== null
            ? $estimatedUnitCost * (int) $request->quantity_requested
            : null;

        $purchaseRequest->update([
            'supplier_id' => $request->supplier_id,
            'quantity_requested' => $request->quantity_requested,
            'estimated_unit_cost' => $estimatedUnitCost,
            'estimated_total_cost' => $estimatedTotalCost,
            'reason' => $request->reason,
            'notes' => $request->notes,
            'updated_by' => $request->user()->id,
        ]);

        $purchaseRequest->load($this->defaultRelations());

        return $this->sendResponse($purchaseRequest, 'Purchase request updated successfully.');
    }

    /**
     * Approve purchase request.
     */
    public function approve(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can approve purchase requests.');
        }

        if (!$purchaseRequest->isPending()) {
            return $this->sendError('Only pending purchase requests can be approved.', [], 400);
        }

        $validator = Validator::make($request->all(), [
            'quantity_approved' => 'nullable|integer|min:1',
            'admin_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $quantityApproved = $request->quantity_approved ?? $purchaseRequest->quantity_requested;

        if ($quantityApproved > $purchaseRequest->quantity_requested) {
            return $this->sendError('Approved quantity cannot be greater than requested quantity.', [], 400);
        }

        $purchaseRequest->update([
            'quantity_approved' => $quantityApproved,
            'status' => PurchaseRequest::STATUS_APPROVED,
            'admin_notes' => $request->admin_notes,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'updated_by' => $request->user()->id,
        ]);

        $purchaseRequest->load($this->defaultRelations());

        return $this->sendResponse($purchaseRequest, 'Purchase request approved successfully.');
    }

    /**
     * Reject purchase request.
     */
    public function reject(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can reject purchase requests.');
        }

        if (!$purchaseRequest->isPending()) {
            return $this->sendError('Only pending purchase requests can be rejected.', [], 400);
        }

        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please provide rejection reason.');
        }

        $purchaseRequest->update([
            'status' => PurchaseRequest::STATUS_REJECTED,
            'rejected_by' => $request->user()->id,
            'rejected_at' => now(),
            'rejection_reason' => $request->rejection_reason,
            'updated_by' => $request->user()->id,
        ]);

        $purchaseRequest->load($this->defaultRelations());

        return $this->sendResponse($purchaseRequest, 'Purchase request rejected successfully.');
    }

    /**
     * Mark purchase request as ordered.
     */
    public function markOrdered(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can mark purchase requests as ordered.');
        }

        if (!$purchaseRequest->isApproved()) {
            return $this->sendError('Only approved purchase requests can be marked as ordered.', [], 400);
        }

        $validator = Validator::make($request->all(), [
            'supplier_id' => 'nullable|exists:suppliers,id',
            'supplier_reference' => 'nullable|string|max:255',
            'admin_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        if (!$purchaseRequest->supplier_id && !$request->filled('supplier_id')) {
            return $this->sendError('Please choose supplier before marking request as ordered.', [], 400);
        }

        $purchaseRequest->update([
            'supplier_id' => $request->supplier_id ?? $purchaseRequest->supplier_id,
            'supplier_reference' => $request->supplier_reference,
            'admin_notes' => $request->admin_notes ?? $purchaseRequest->admin_notes,
            'status' => PurchaseRequest::STATUS_ORDERED,
            'ordered_by' => $request->user()->id,
            'ordered_at' => now(),
            'updated_by' => $request->user()->id,
        ]);

        $purchaseRequest->load($this->defaultRelations());

        return $this->sendResponse($purchaseRequest, 'Purchase request marked as ordered successfully.');
    }

    /**
     * Receive stock from purchase request.
     */
    public function receive(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can receive purchase requests.');
        }

        if (!$purchaseRequest->canBeReceived()) {
            return $this->sendError('This purchase request cannot receive stock now.', [], 400);
        }

        $validator = Validator::make($request->all(), [
            'quantity_received' => 'required|integer|min:1',
            'received_unit_cost' => 'nullable|numeric|min:0',
            'supplier_reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        try {
            $purchaseRequest = DB::transaction(function () use ($request, $purchaseRequest) {
                $purchaseRequest = PurchaseRequest::where('id', $purchaseRequest->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $remainingQuantity = $purchaseRequest->remainingQuantity();
                $quantityToReceive = (int) $request->quantity_received;

                if ($quantityToReceive > $remainingQuantity) {
                    throw new \Exception('Received quantity cannot be greater than remaining approved quantity. Remaining: ' . $remainingQuantity);
                }

                $stock = InventoryStock::where('id', $purchaseRequest->inventory_stock_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $unitCost = $request->received_unit_cost !== null
                    ? (float) $request->received_unit_cost
                    : ($purchaseRequest->estimated_unit_cost !== null ? (float) $purchaseRequest->estimated_unit_cost : null);

                $quantityBefore = $stock->quantity;
                $quantityAfter = $quantityBefore + $quantityToReceive;

                $stock->update([
                    'quantity' => $quantityAfter,
                    'last_restocked_at' => now(),
                    'updated_by' => $request->user()->id,
                ]);

                StockMovement::create([
                    'inventory_stock_id' => $stock->id,
                    'food_item_id' => $purchaseRequest->food_item_id,
                    'movement_type' => StockMovement::TYPE_RESTOCK,
                    'quantity_before' => $quantityBefore,
                    'quantity_change' => $quantityToReceive,
                    'quantity_after' => $quantityAfter,
                    'unit_cost' => $unitCost,
                    'total_cost' => $unitCost !== null ? $unitCost * $quantityToReceive : null,
                    'reference_type' => 'purchase_request',
                    'reference_id' => $purchaseRequest->id,
                    'reference_number' => $purchaseRequest->request_number,
                    'reason' => 'Purchase request stock received',
                    'notes' => $request->notes,
                    'movement_date' => now(),
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);

                $newReceivedQuantity = $purchaseRequest->quantity_received + $quantityToReceive;
                $newReceivedTotalCost = (float) $purchaseRequest->received_total_cost;

                if ($unitCost !== null) {
                    $newReceivedTotalCost += $unitCost * $quantityToReceive;
                }

                $newStatus = $newReceivedQuantity >= $purchaseRequest->quantity_approved
                    ? PurchaseRequest::STATUS_RECEIVED
                    : PurchaseRequest::STATUS_PARTIALLY_RECEIVED;

                $purchaseRequest->update([
                    'quantity_received' => $newReceivedQuantity,
                    'received_unit_cost' => $unitCost,
                    'received_total_cost' => $newReceivedTotalCost,
                    'supplier_reference' => $request->supplier_reference ?? $purchaseRequest->supplier_reference,
                    'status' => $newStatus,
                    'received_by' => $request->user()->id,
                    'received_at' => now(),
                    'notes' => $request->notes ?? $purchaseRequest->notes,
                    'updated_by' => $request->user()->id,
                ]);

                $this->resolveLowStockAlertsIfRecovered($stock, $request->user()->id);

                return $purchaseRequest;
            });

            $purchaseRequest->load($this->defaultRelations());

            return $this->sendResponse($purchaseRequest, 'Purchase request stock received successfully.');
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }
    }

    /**
     * Cancel purchase request.
     */
    public function cancel(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can cancel purchase requests.');
        }

        if ($purchaseRequest->isReceived()) {
            return $this->sendError('Received purchase requests cannot be cancelled.', [], 400);
        }

        if ($purchaseRequest->isCancelled()) {
            return $this->sendError('Purchase request is already cancelled.', [], 400);
        }

        if ($purchaseRequest->isRejected()) {
            return $this->sendError('Rejected purchase requests cannot be cancelled.', [], 400);
        }

        $validator = Validator::make($request->all(), [
            'cancellation_reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please provide cancellation reason.');
        }

        $purchaseRequest->update([
            'status' => PurchaseRequest::STATUS_CANCELLED,
            'cancelled_by' => $request->user()->id,
            'cancelled_at' => now(),
            'cancellation_reason' => $request->cancellation_reason,
            'updated_by' => $request->user()->id,
        ]);

        $purchaseRequest->load($this->defaultRelations());

        return $this->sendResponse($purchaseRequest, 'Purchase request cancelled successfully.');
    }

    /**
     * Delete purchase request.
     */
    public function destroy(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can delete purchase requests.');
        }

        if ($purchaseRequest->isReceived() || $purchaseRequest->isPartiallyReceived()) {
            return $this->sendError('Received or partially received purchase requests cannot be deleted.', [], 400);
        }

        $purchaseRequest->delete();

        return $this->sendResponse([], 'Purchase request deleted successfully.');
    }

    /**
     * Restore deleted purchase request.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can restore purchase requests.');
        }

        $purchaseRequest = PurchaseRequest::onlyTrashed()->find($id);

        if (!$purchaseRequest) {
            return $this->sendNotFound('Deleted purchase request not found.');
        }

        $purchaseRequest->restore();

        $purchaseRequest->load($this->defaultRelations());

        return $this->sendResponse($purchaseRequest, 'Purchase request restored successfully.');
    }

    /**
     * Purchase request summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $query = PurchaseRequest::query();

        if ($request->filled('from_date')) {
            $query->whereDate('requested_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('requested_at', '<=', $request->to_date);
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->filled('food_item_id')) {
            $query->where('food_item_id', $request->food_item_id);
        }

        $totalRequests = (clone $query)->count();

        $pendingRequests = (clone $query)
            ->where('status', PurchaseRequest::STATUS_PENDING)
            ->count();

        $approvedRequests = (clone $query)
            ->where('status', PurchaseRequest::STATUS_APPROVED)
            ->count();

        $orderedRequests = (clone $query)
            ->where('status', PurchaseRequest::STATUS_ORDERED)
            ->count();

        $receivedRequests = (clone $query)
            ->where('status', PurchaseRequest::STATUS_RECEIVED)
            ->count();

        $totalEstimatedCost = (clone $query)->sum('estimated_total_cost');
        $totalReceivedCost = (clone $query)->sum('received_total_cost');

        $byStatus = (clone $query)
            ->select('status', DB::raw('COUNT(*) as total_records'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        return $this->sendResponse([
            'total_requests' => $totalRequests,
            'pending_requests' => $pendingRequests,
            'approved_requests' => $approvedRequests,
            'ordered_requests' => $orderedRequests,
            'received_requests' => $receivedRequests,
            'total_estimated_cost' => $totalEstimatedCost,
            'total_received_cost' => $totalReceivedCost,
            'by_status' => $byStatus,
        ], 'Purchase request summary retrieved successfully.');
    }

    /**
     * Default relations.
     */
    private function defaultRelations(): array
    {
        return [
            'supplier:id,supplier_code,name,contact_person,email,phone,status',
            'inventoryStock:id,food_item_id,quantity,reserved_quantity,low_stock_quantity,location,status,last_restocked_at',
            'foodItem:id,food_category_id,name,sku,price,cost_price,unit,status,is_available',
            'foodItem.category:id,name',
            'lowStockAlert:id,alert_number,alert_type,severity,status,current_quantity,threshold_quantity',
            'requestedBy:id,name,email,phone,role',
            'approvedBy:id,name,email,phone,role',
            'rejectedBy:id,name,email,phone,role',
            'orderedBy:id,name,email,phone,role',
            'receivedBy:id,name,email,phone,role',
            'cancelledBy:id,name,email,phone,role',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ];
    }

    /**
     * Generate unique request number.
     */
    private function generateRequestNumber(): string
    {
        do {
            $number = 'PR-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
        } while (PurchaseRequest::where('request_number', $number)->exists());

        return $number;
    }

    /**
     * Resolve active low stock alerts if stock is now above threshold.
     */
    private function resolveLowStockAlertsIfRecovered(InventoryStock $stock, int $userId): void
    {
        $stock->loadMissing('foodItem');

        $threshold = $this->getThresholdQuantity($stock);

        if ($stock->quantity <= $threshold) {
            return;
        }

        LowStockAlert::where('inventory_stock_id', $stock->id)
            ->where('status', LowStockAlert::STATUS_ACTIVE)
            ->update([
                'status' => LowStockAlert::STATUS_RESOLVED,
                'resolved_by' => $userId,
                'resolved_at' => now(),
                'resolution_notes' => 'Resolved automatically after purchase request stock was received.',
                'updated_by' => $userId,
            ]);
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