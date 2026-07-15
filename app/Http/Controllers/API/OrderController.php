<?php

namespace App\Http\Controllers\API;

use App\Models\FoodCategory;
use App\Models\FoodItem;
use App\Models\InventoryStock;
use App\Models\LowStockAlert;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderQrCode;
use App\Models\PickupConfirmation;
use App\Models\QrScanLog;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class OrderController extends BaseController
{
    /**
     * Display orders.
     */
    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();

        $query = Order::query()
            ->with($this->defaultRelations())
            ->orderByDesc('id');

        if (!$authUser->canManageOrders()) {
            $query->where('user_id', $authUser->id);
        }

        if ($request->filled('user_id') && $authUser->canManageOrders()) {
            $query->where('user_id', $request->user_id);
        }

        /*
         * Support both backend `order_status` and frontend `status`.
         *
         * The dashboard displays the initial order stage as "Pending",
         * while newer wallet-paid orders are stored as "confirmed".
         * Therefore, requesting pending orders returns both legacy
         * `pending` and current `confirmed` records.
         */
        $requestedOrderStatus = $request->input(
            'order_status',
            $request->input('status')
        );

        if (
            $requestedOrderStatus !== null &&
            trim((string) $requestedOrderStatus) !== ''
        ) {
            $normalizedStatus = strtolower(
                trim((string) $requestedOrderStatus)
            );

            if ($normalizedStatus === 'pending') {
                $query->whereIn(
                    'order_status',
                    $this->initialOrderStatuses()
                );
            } else {
                $query->where(
                    'order_status',
                    $normalizedStatus
                );
            }
        }

        foreach ([
            'payment_status',
            'pickup_status',
            'order_type',
        ] as $field) {
            if ($request->filled($field)) {
                $query->where(
                    $field,
                    $request->input($field)
                );
            }
        }

        if (
            $authUser->canManageOrders() &&
            (
                $request->boolean('with_trashed') ||
                $request->boolean('include_deleted')
            )
        ) {
            $query->withTrashed();
        }

        if ($request->filled('from_date')) {
            $query->whereDate('ordered_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('ordered_at', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', '%' . $search . '%')
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery
                            ->where('name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%')
                            ->orWhere('phone', 'like', '%' . $search . '%');
                    });
            });
        }

        $perPage = min(max((int) $request->get('per_page', 20), 1), 200);
        $orders = $query->paginate($perPage);

        return $this->sendResponse(
            $orders,
            'Orders retrieved successfully.'
        );
    }

    /**
     * Create an order, pay by wallet, reduce available stock,
     * create stock movements, and generate the pickup QR code.
     */
    public function store(Request $request): JsonResponse
    {
        $authUser = $request->user();

        $validator = Validator::make($request->all(), [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'order_type' => ['nullable', 'in:pickup,dine_in,takeaway'],
            'payment_method' => ['nullable', 'in:wallet'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'customer_notes' => ['nullable', 'string'],
            'staff_notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.food_item_id' => [
                'required',
                'integer',
                'distinct',
                'exists:food_items,id',
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        $targetUserId = $authUser->canManageOrders()
            ? (int) ($request->user_id ?? $authUser->id)
            : (int) $authUser->id;

        try {
            $order = DB::transaction(function () use (
                $request,
                $authUser,
                $targetUserId
            ) {
                $customer = User::query()
                    ->whereKey($targetUserId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (!$customer->isActive()) {
                    throw new \RuntimeException(
                        'Customer account is not active.'
                    );
                }

                $subtotal = 0.0;
                $preparedItems = [];

                foreach ($request->items as $itemData) {
                    $foodItem = FoodItem::query()
                        ->with('category:id,status')
                        ->whereKey($itemData['food_item_id'])
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (
                        !$foodItem->isActive() ||
                        !$foodItem->isAvailable() ||
                        !$foodItem->category ||
                        $foodItem->category->status !== FoodCategory::STATUS_ACTIVE
                    ) {
                        throw new \RuntimeException(
                            $foodItem->name . ' is not available.'
                        );
                    }

                    $stock = InventoryStock::query()
                        ->where('food_item_id', $foodItem->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$stock) {
                        throw new \RuntimeException(
                            'Stock record not found for ' . $foodItem->name . '.'
                        );
                    }

                    if ($stock->status !== InventoryStock::STATUS_ACTIVE) {
                        throw new \RuntimeException(
                            'Stock is inactive for ' . $foodItem->name . '.'
                        );
                    }

                    $quantity = (int) $itemData['quantity'];
                    $availableQuantity = $this->availableQuantity($stock);

                    if ($availableQuantity < $quantity) {
                        throw new \RuntimeException(
                            'Not enough available stock for ' .
                            $foodItem->name . '. Available: ' .
                            $availableQuantity . '.'
                        );
                    }

                    $unitPrice = (float) $foodItem->price;
                    $lineSubtotal = $unitPrice * $quantity;
                    $subtotal += $lineSubtotal;

                    $preparedItems[] = [
                        'food_item' => $foodItem,
                        'stock' => $stock,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'line_subtotal' => $lineSubtotal,
                        'notes' => $itemData['notes'] ?? null,
                    ];
                }

                $discount = $authUser->canManageOrders()
                    ? (float) ($request->discount_amount ?? 0)
                    : 0.0;

                $tax = $authUser->canManageOrders()
                    ? (float) ($request->tax_amount ?? 0)
                    : 0.0;

                if ($discount > $subtotal) {
                    throw new \RuntimeException(
                        'Discount amount cannot exceed the subtotal.'
                    );
                }

                $total = ($subtotal - $discount) + $tax;

                if ($total <= 0) {
                    throw new \RuntimeException(
                        'Order total must be greater than zero.'
                    );
                }

                $balanceBefore = (float) $customer->wallet_balance;

                if ($total > $balanceBefore) {
                    throw new \RuntimeException(
                        'Not enough wallet balance.'
                    );
                }

                $order = Order::create([
                    'user_id' => $customer->id,
                    'order_number' => $this->generateOrderNumber(),
                    'order_type' => $request->order_type ?? Order::TYPE_PICKUP,
                    'subtotal_amount' => $subtotal,
                    'discount_amount' => $discount,
                    'tax_amount' => $tax,
                    'total_amount' => $total,
                    'paid_amount' => $total,
                    'payment_method' => Order::PAYMENT_METHOD_WALLET,
                    'payment_status' => Order::PAYMENT_PAID,
                    'order_status' => Order::STATUS_CONFIRMED,
                    'pickup_status' => Order::PICKUP_PENDING,
                    'customer_notes' => $request->customer_notes,
                    'staff_notes' => $authUser->canManageOrders()
                        ? $request->staff_notes
                        : null,
                    'ordered_at' => now(),
                    'paid_at' => now(),
                    'confirmed_by' => $authUser->id,
                    'confirmed_at' => now(),
                ]);

                foreach ($preparedItems as $preparedItem) {
                    /** @var FoodItem $foodItem */
                    $foodItem = $preparedItem['food_item'];
                    /** @var InventoryStock $stock */
                    $stock = $preparedItem['stock'];
                    $quantity = $preparedItem['quantity'];

                    OrderItem::create([
                        'order_id' => $order->id,
                        'food_item_id' => $foodItem->id,
                        'food_name' => $foodItem->name,
                        'food_sku' => $foodItem->sku,
                        'unit' => $foodItem->unit,
                        'quantity' => $quantity,
                        'unit_price' => $preparedItem['unit_price'],
                        'cost_price' => $foodItem->cost_price,
                        'subtotal_amount' => $preparedItem['line_subtotal'],
                        'discount_amount' => 0,
                        'tax_amount' => 0,
                        'total_amount' => $preparedItem['line_subtotal'],
                        'item_status' => OrderItem::STATUS_CONFIRMED,
                        'notes' => $preparedItem['notes'],
                        'created_by' => $authUser->id,
                        'updated_by' => $authUser->id,
                    ]);

                    $quantityBefore = (int) $stock->quantity;
                    $quantityAfter = $quantityBefore - $quantity;

                    $stock->update([
                        'quantity' => $quantityAfter,
                        'updated_by' => $authUser->id,
                    ]);

                    StockMovement::create([
                        'inventory_stock_id' => $stock->id,
                        'food_item_id' => $foodItem->id,
                        'movement_type' => StockMovement::TYPE_SALE,
                        'quantity_before' => $quantityBefore,
                        'quantity_change' => -abs($quantity),
                        'quantity_after' => $quantityAfter,
                        'unit_cost' => $foodItem->cost_price,
                        'total_cost' => $foodItem->cost_price !== null
                            ? (float) $foodItem->cost_price * $quantity
                            : null,
                        'reference_type' => 'order',
                        'reference_id' => $order->id,
                        'reference_number' => $order->order_number,
                        'reason' => 'Food order sale',
                        'notes' => 'Stock reduced after order payment',
                        'movement_date' => now(),
                        'created_by' => $authUser->id,
                        'updated_by' => $authUser->id,
                    ]);

                    $stock->load('foodItem');
                    $this->syncLowStockAlert($stock, $authUser->id);
                }

                $balanceAfter = $balanceBefore - $total;

                $customer->update([
                    'wallet_balance' => $balanceAfter,
                ]);

                WalletTransaction::create([
                    'user_id' => $customer->id,
                    'wallet_top_up_id' => null,
                    'transaction_number' => $this->generateWalletTransactionNumber(),
                    'transaction_type' => WalletTransaction::TYPE_DEBIT,
                    'source_type' => WalletTransaction::SOURCE_ORDER_PAYMENT,
                    'source_id' => $order->id,
                    'reference_number' => $order->order_number,
                    'amount' => $total,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'status' => WalletTransaction::STATUS_COMPLETED,
                    'description' => 'Food order payment',
                    'notes' => 'Payment deducted from wallet',
                    'processed_by' => $authUser->id,
                    'processed_at' => now(),
                ]);

                $qrToken = $this->generateQrToken();
                $qrCodeNumber = $this->generateQrCodeNumber();

                OrderQrCode::create([
                    'order_id' => $order->id,
                    'user_id' => $customer->id,
                    'qr_code_number' => $qrCodeNumber,
                    'qr_token' => $qrToken,
                    'qr_payload' => $this->buildQrPayload(
                        $order,
                        $qrCodeNumber,
                        $qrToken
                    ),
                    'qr_image' => null,
                    'status' => OrderQrCode::STATUS_ACTIVE,
                    'expires_at' => now()->addDay(),
                    'used_at' => null,
                    'scanned_by' => null,
                    'scanned_at' => null,
                    'cancelled_by' => null,
                    'cancelled_at' => null,
                    'notes' => 'QR code generated after successful order payment.',
                    'created_by' => $authUser->id,
                    'updated_by' => $authUser->id,
                ]);

                return $order;
            });

            $order->load($this->defaultRelations());

            return $this->sendCreated(
                $order,
                'Order created successfully.'
            );
        } catch (\Throwable $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }
    }

    /**
     * Display one order.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $authUser = $request->user();

        if (
            !$authUser->canManageOrders() &&
            $order->user_id !== $authUser->id
        ) {
            return $this->sendForbidden(
                'You can only view your own orders.'
            );
        }

        $order->load($this->defaultRelations());

        return $this->sendResponse(
            $order,
            'Order retrieved successfully.'
        );
    }

    /**
     * Update order notes without changing payment or stock fields.
     */
    public function update(Request $request, Order $order): JsonResponse
    {
        $authUser = $request->user();

        if (
            !$authUser->canManageOrders() &&
            $order->user_id !== $authUser->id
        ) {
            return $this->sendForbidden(
                'You can only update your own order notes.'
            );
        }

        if ($order->isCompleted() || $order->isCancelled()) {
            return $this->sendError(
                'Completed or cancelled orders cannot be updated.',
                [],
                400
            );
        }

        $validator = Validator::make($request->all(), [
            'customer_notes' => ['sometimes', 'nullable', 'string'],
            'staff_notes' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        $data = [];

        if ($request->has('customer_notes')) {
            $data['customer_notes'] = $request->customer_notes;
        }

        if ($authUser->canManageOrders() && $request->has('staff_notes')) {
            $data['staff_notes'] = $request->staff_notes;
        }

        $order->update($data);
        $order->load($this->defaultRelations());

        return $this->sendResponse(
            $order,
            'Order updated successfully.'
        );
    }

    /**
     * Delete only a cancelled order.
     */
    public function destroy(Request $request, Order $order): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden(
                'Only admin or staff can delete orders.'
            );
        }

        if (!$order->isCancelled()) {
            return $this->sendError(
                'Cancel the order before deleting it.',
                [],
                400
            );
        }

        $order->delete();

        return $this->sendResponse([], 'Order deleted successfully.');
    }

    /**
     * Move PENDING/CONFIRMED -> PREPARING.
     *
     * Older orders may use `pending`, while current wallet-paid orders
     * are created as `confirmed`. Both represent the initial kitchen queue.
     */
    public function markPreparing(
        Request $request,
        Order $order
    ): JsonResponse {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden(
                'Only admin or staff can update order preparation status.'
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

        try {
            $order = DB::transaction(function () use (
                $request,
                $order
            ) {
                $lockedOrder = Order::query()
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $currentStatus = strtolower(
                    trim((string) $lockedOrder->order_status)
                );

                if (
                    !in_array(
                        $currentStatus,
                        $this->initialOrderStatuses(),
                        true
                    )
                ) {
                    throw new \RuntimeException(
                        'Only pending or confirmed orders can be marked as preparing. Current status: ' .
                        ($lockedOrder->order_status ?: 'unknown') .
                        '.'
                    );
                }

                if (
                    $lockedOrder->payment_status !==
                    Order::PAYMENT_PAID
                ) {
                    throw new \RuntimeException(
                        'Only paid orders can be marked as preparing.'
                    );
                }

                $updateData = [
                    'order_status' => Order::STATUS_PREPARING,
                ];

                /*
                 * Legacy pending orders may not have confirmation metadata.
                 */
                if (!$lockedOrder->confirmed_by) {
                    $updateData['confirmed_by'] =
                        $request->user()->id;
                }

                if (!$lockedOrder->confirmed_at) {
                    $updateData['confirmed_at'] = now();
                }

                if ($request->filled('notes')) {
                    $existingNotes = trim(
                        (string) $lockedOrder->staff_notes
                    );

                    $newNote = trim(
                        (string) $request->notes
                    );

                    $updateData['staff_notes'] =
                        $existingNotes !== ''
                            ? $existingNotes . PHP_EOL . $newNote
                            : $newNote;
                }

                $lockedOrder->update($updateData);

                /*
                 * Keep item statuses coherent with the parent order.
                 */
                $lockedOrder->orderItems()
                    ->whereIn('item_status', [
                        'pending',
                        OrderItem::STATUS_CONFIRMED,
                    ])
                    ->update([
                        'item_status' =>
                            OrderItem::STATUS_CONFIRMED,
                        'updated_by' =>
                            $request->user()->id,
                    ]);

                return $lockedOrder;
            });
        } catch (\Throwable $e) {
            return $this->sendError(
                $e->getMessage(),
                [],
                400
            );
        }

        $order->load($this->defaultRelations());

        return $this->sendResponse(
            $order,
            'Order marked as preparing successfully.'
        );
    }

    /**
     * Move PREPARING -> READY and mark items prepared.
     */
    public function markReady(
        Request $request,
        Order $order
    ): JsonResponse {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden(
                'Only admin or staff can mark orders as ready.'
            );
        }

        if ($order->order_status !== Order::STATUS_PREPARING) {
            return $this->sendError(
                'Only preparing orders can be marked as ready.',
                [],
                400
            );
        }

        DB::transaction(function () use ($request, $order) {
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->order_status !== Order::STATUS_PREPARING) {
                throw new \RuntimeException(
                    'The order status changed. Refresh and try again.'
                );
            }

            $lockedOrder->update([
                'order_status' => Order::STATUS_READY,
                'pickup_status' => Order::PICKUP_READY,
                'ready_by' => $request->user()->id,
                'ready_at' => now(),
            ]);

            $lockedOrder->orderItems()->update([
                'item_status' => 'prepared',
                'updated_by' => $request->user()->id,
            ]);
        });

        $order->refresh()->load($this->defaultRelations());

        return $this->sendResponse(
            $order,
            'Order marked as ready successfully.'
        );
    }

    /**
     * Complete a ready order using its QR record.
     *
     * This endpoint performs the same official pickup records as QR mark-used:
     * QR used, order completed, items collected, scan log, and confirmation.
     */
    public function complete(Request $request, Order $order): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden(
                'Only admin or staff can complete orders.'
            );
        }

        $validator = Validator::make($request->all(), [
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_type' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:255'],
            'scanned_payload' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        try {
            $order = DB::transaction(function () use ($request, $order) {
                $lockedOrder = Order::with(['user', 'orderItems'])
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $lockedOrder->order_status !== Order::STATUS_READY ||
                    $lockedOrder->pickup_status !== Order::PICKUP_READY
                ) {
                    throw new \RuntimeException(
                        'Only an order that is ready for pickup can be completed.'
                    );
                }

                if ($lockedOrder->payment_status !== Order::PAYMENT_PAID) {
                    throw new \RuntimeException(
                        'Only paid orders can be completed.'
                    );
                }

                if (
                    PickupConfirmation::where('order_id', $lockedOrder->id)
                        ->exists()
                ) {
                    throw new \RuntimeException(
                        'Pickup has already been confirmed for this order.'
                    );
                }

                $qrCode = OrderQrCode::query()
                    ->where('order_id', $lockedOrder->id)
                    ->lockForUpdate()
                    ->first();

                if (!$qrCode) {
                    throw new \RuntimeException(
                        'Pickup QR code was not found for this order.'
                    );
                }

                if ($qrCode->isCancelled()) {
                    throw new \RuntimeException(
                        'Cancelled QR code cannot be used.'
                    );
                }

                if ($qrCode->isUsed()) {
                    throw new \RuntimeException(
                        'This QR code has already been used.'
                    );
                }

                if ($qrCode->isExpired()) {
                    $qrCode->update([
                        'status' => OrderQrCode::STATUS_EXPIRED,
                        'updated_by' => $request->user()->id,
                    ]);

                    throw new \RuntimeException(
                        'This QR code has expired.'
                    );
                }

                $qrCode->update([
                    'status' => OrderQrCode::STATUS_USED,
                    'used_at' => now(),
                    'scanned_by' => $request->user()->id,
                    'scanned_at' => now(),
                    'updated_by' => $request->user()->id,
                ]);

                $scanLog = QrScanLog::create([
                    'order_qr_code_id' => $qrCode->id,
                    'order_id' => $lockedOrder->id,
                    'user_id' => $lockedOrder->user_id,
                    'scanned_by' => $request->user()->id,
                    'scan_action' => QrScanLog::ACTION_COLLECT,
                    'scan_status' => QrScanLog::STATUS_SUCCESS,
                    'qr_code_number' => $qrCode->qr_code_number,
                    'qr_token' => $qrCode->qr_token,
                    'scanned_payload' => $request->scanned_payload
                        ?? $qrCode->qr_payload,
                    'message' => 'Order completed and pickup confirmed successfully.',
                    'failure_reason' => null,
                    'device_name' => $request->device_name,
                    'device_type' => $request->device_type,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'location' => $request->location,
                    'scanned_at' => now(),
                ]);

                $lockedOrder->update([
                    'order_status' => Order::STATUS_COMPLETED,
                    'pickup_status' => Order::PICKUP_COLLECTED,
                    'completed_by' => $request->user()->id,
                    'completed_at' => now(),
                ]);

                $lockedOrder->orderItems()->update([
                    'item_status' => OrderItem::STATUS_COLLECTED,
                    'updated_by' => $request->user()->id,
                ]);

                PickupConfirmation::create([
                    'order_id' => $lockedOrder->id,
                    'order_qr_code_id' => $qrCode->id,
                    'qr_scan_log_id' => $scanLog->id,
                    'user_id' => $lockedOrder->user_id,
                    'confirmation_number' => $this->generatePickupConfirmationNumber(),
                    'confirmation_method' => PickupConfirmation::METHOD_QR_SCAN,
                    'status' => PickupConfirmation::STATUS_CONFIRMED,
                    'customer_name' => $lockedOrder->user?->name,
                    'customer_phone' => $lockedOrder->user?->phone,
                    'order_number' => $lockedOrder->order_number,
                    'qr_code_number' => $qrCode->qr_code_number,
                    'confirmed_by' => $request->user()->id,
                    'confirmed_at' => now(),
                    'device_name' => $request->device_name,
                    'device_type' => $request->device_type,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'location' => $request->location,
                    'notes' => $request->notes
                        ?? 'Pickup confirmed from the order completion endpoint.',
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);

                return $lockedOrder;
            });
        } catch (\Throwable $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }

        $order->load($this->defaultRelations());

        return $this->sendResponse(
            $order,
            'Order completed and pickup confirmed successfully.'
        );
    }

    /**
     * Cancel an eligible order, return stock, refund wallet, and cancel QR.
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        $authUser = $request->user();

        if (
            !$authUser->canManageOrders() &&
            $order->user_id !== $authUser->id
        ) {
            return $this->sendForbidden(
                'You can only cancel your own order.'
            );
        }

        $validator = Validator::make($request->all(), [
            'cancellation_reason' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        try {
            $order = DB::transaction(function () use (
                $request,
                $authUser,
                $order
            ) {
                $lockedOrder = Order::query()
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (!$lockedOrder->canBeCancelled()) {
                    throw new \RuntimeException(
                        'This order cannot be cancelled.'
                    );
                }

                $orderItems = $lockedOrder->orderItems()
                    ->withTrashed()
                    ->get();

                foreach ($orderItems as $orderItem) {
                    if ($orderItem->isCancelled()) {
                        continue;
                    }

                    $stock = InventoryStock::query()
                        ->where('food_item_id', $orderItem->food_item_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$stock) {
                        throw new \RuntimeException(
                            'Stock record not found for ' .
                            $orderItem->food_name . '.'
                        );
                    }

                    $quantityBefore = (int) $stock->quantity;
                    $quantityReturned = (int) $orderItem->quantity;
                    $quantityAfter = $quantityBefore + $quantityReturned;

                    $stock->update([
                        'quantity' => $quantityAfter,
                        'updated_by' => $authUser->id,
                    ]);

                    StockMovement::create([
                        'inventory_stock_id' => $stock->id,
                        'food_item_id' => $orderItem->food_item_id,
                        'movement_type' => StockMovement::TYPE_RETURN,
                        'quantity_before' => $quantityBefore,
                        'quantity_change' => $quantityReturned,
                        'quantity_after' => $quantityAfter,
                        'unit_cost' => $orderItem->cost_price,
                        'total_cost' => $orderItem->cost_price !== null
                            ? (float) $orderItem->cost_price * $quantityReturned
                            : null,
                        'reference_type' => 'order_cancel',
                        'reference_id' => $lockedOrder->id,
                        'reference_number' => $lockedOrder->order_number,
                        'reason' => 'Order cancelled',
                        'notes' => $request->cancellation_reason,
                        'movement_date' => now(),
                        'created_by' => $authUser->id,
                        'updated_by' => $authUser->id,
                    ]);

                    $orderItem->update([
                        'item_status' => OrderItem::STATUS_CANCELLED,
                        'updated_by' => $authUser->id,
                    ]);

                    $stock->load('foodItem');
                    $this->syncLowStockAlert($stock, $authUser->id);
                }

                if (
                    $lockedOrder->payment_method === Order::PAYMENT_METHOD_WALLET &&
                    $lockedOrder->payment_status === Order::PAYMENT_PAID
                ) {
                    $customer = User::query()
                        ->whereKey($lockedOrder->user_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $refundExists = WalletTransaction::query()
                        ->where('source_type', WalletTransaction::SOURCE_REFUND)
                        ->where('source_id', $lockedOrder->id)
                        ->where('status', WalletTransaction::STATUS_COMPLETED)
                        ->exists();

                    if ($refundExists) {
                        throw new \RuntimeException(
                            'This order has already been refunded.'
                        );
                    }

                    $balanceBefore = (float) $customer->wallet_balance;
                    $refundAmount = (float) $lockedOrder->paid_amount;
                    $balanceAfter = $balanceBefore + $refundAmount;

                    $customer->update([
                        'wallet_balance' => $balanceAfter,
                    ]);

                    WalletTransaction::create([
                        'user_id' => $customer->id,
                        'wallet_top_up_id' => null,
                        'transaction_number' => $this->generateWalletTransactionNumber(),
                        'transaction_type' => WalletTransaction::TYPE_CREDIT,
                        'source_type' => WalletTransaction::SOURCE_REFUND,
                        'source_id' => $lockedOrder->id,
                        'reference_number' => $lockedOrder->order_number,
                        'amount' => $refundAmount,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceAfter,
                        'status' => WalletTransaction::STATUS_COMPLETED,
                        'description' => 'Order payment refund',
                        'notes' => $request->cancellation_reason,
                        'processed_by' => $authUser->id,
                        'processed_at' => now(),
                    ]);

                    $lockedOrder->payment_status = Order::PAYMENT_REFUNDED;
                }

                $qrCode = OrderQrCode::query()
                    ->where('order_id', $lockedOrder->id)
                    ->lockForUpdate()
                    ->first();

                if ($qrCode && !$qrCode->isUsed()) {
                    $qrCode->update([
                        'status' => OrderQrCode::STATUS_CANCELLED,
                        'cancelled_by' => $authUser->id,
                        'cancelled_at' => now(),
                        'notes' => $request->cancellation_reason,
                        'updated_by' => $authUser->id,
                    ]);
                }

                $lockedOrder->order_status = Order::STATUS_CANCELLED;
                $lockedOrder->pickup_status = Order::PICKUP_CANCELLED;
                $lockedOrder->cancelled_by = $authUser->id;
                $lockedOrder->cancelled_at = now();
                $lockedOrder->cancellation_reason = $request->cancellation_reason;
                $lockedOrder->save();

                return $lockedOrder;
            });
        } catch (\Throwable $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }

        $order->load($this->defaultRelations());

        return $this->sendResponse(
            $order,
            'Order cancelled, wallet refunded, stock returned, and QR code cancelled successfully.'
        );
    }

    /**
     * Restore a soft-deleted order.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden(
                'Only admin or staff can restore orders.'
            );
        }

        $order = Order::onlyTrashed()->find($id);

        if (!$order) {
            return $this->sendNotFound('Deleted order not found.');
        }

        if (!$order->isCancelled()) {
            return $this->sendError(
                'Only a cancelled order history record can be restored.',
                [],
                400
            );
        }

        $order->restore();
        $order->load($this->defaultRelations());

        return $this->sendResponse(
            $order,
            'Order restored successfully.'
        );
    }

    /**
     * Order summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $authUser = $request->user();
        $query = Order::query();

        if (!$authUser->canManageOrders()) {
            $query->where('user_id', $authUser->id);
        }

        if ($request->filled('user_id') && $authUser->canManageOrders()) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('ordered_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('ordered_at', '<=', $request->to_date);
        }

        if (
            $authUser->canManageOrders() &&
            (
                $request->boolean('with_trashed') ||
                $request->boolean('include_deleted')
            )
        ) {
            $query->withTrashed();
        }

        $totalOrders = (clone $query)->count();

        /*
         * The dashboard calls the initial queue "Pending".
         * Count both legacy `pending` and current `confirmed` records.
         */
        $pendingOrders = (clone $query)
            ->whereIn(
                'order_status',
                $this->initialOrderStatuses()
            )
            ->count();

        $confirmedOrders = (clone $query)
            ->where(
                'order_status',
                Order::STATUS_CONFIRMED
            )
            ->count();

        $preparingOrders = (clone $query)
            ->where(
                'order_status',
                Order::STATUS_PREPARING
            )
            ->count();

        $readyOrders = (clone $query)
            ->where(
                'order_status',
                Order::STATUS_READY
            )
            ->count();

        $completedOrders = (clone $query)
            ->where(
                'order_status',
                Order::STATUS_COMPLETED
            )
            ->count();

        $cancelledOrders = (clone $query)
            ->where(
                'order_status',
                Order::STATUS_CANCELLED
            )
            ->count();

        $paidOrders = (clone $query)
            ->where(
                'payment_status',
                Order::PAYMENT_PAID
            )
            ->count();

        $totalSales = (float) (clone $query)
            ->where(
                'payment_status',
                Order::PAYMENT_PAID
            )
            ->sum('paid_amount');

        $completedSales = (float) (clone $query)
            ->where(
                'order_status',
                Order::STATUS_COMPLETED
            )
            ->where(
                'payment_status',
                Order::PAYMENT_PAID
            )
            ->sum('paid_amount');

        $refundedAmount = (float) (clone $query)
            ->where(
                'payment_status',
                Order::PAYMENT_REFUNDED
            )
            ->sum('paid_amount');

        $byStatus = (clone $query)
            ->select(
                'order_status',
                DB::raw('COUNT(*) as total_records'),
                DB::raw('SUM(total_amount) as total_amount')
            )
            ->groupBy('order_status')
            ->orderBy('order_status')
            ->get();

        $byPaymentStatus = (clone $query)
            ->select(
                'payment_status',
                DB::raw('COUNT(*) as total_records'),
                DB::raw('SUM(paid_amount) as total_paid')
            )
            ->groupBy('payment_status')
            ->orderBy('payment_status')
            ->get();

        return $this->sendResponse([
            'total_orders' => $totalOrders,

            /*
             * Keys used directly by the Next.js order dashboard.
             */
            'pending_orders' => $pendingOrders,
            'preparing_orders' => $preparingOrders,
            'ready_orders' => $readyOrders,
            'completed_orders' => $completedOrders,
            'cancelled_orders' => $cancelledOrders,
            'total_sales' => $totalSales,
            'completed_sales' => $completedSales,
            'refunded_amount' => $refundedAmount,

            /*
             * Retained for existing clients and detailed reports.
             */
            'confirmed_orders' => $confirmedOrders,
            'paid_orders' => $paidOrders,
            'by_status' => $byStatus,
            'by_payment_status' => $byPaymentStatus,
        ], 'Order summary retrieved successfully.');
    }

    /**
     * Initial order statuses accepted before kitchen preparation.
     *
     * `pending` is retained for orders created before the controller update.
     * New wallet-paid orders use Order::STATUS_CONFIRMED.
     */
    private function initialOrderStatuses(): array
    {
        return array_values(array_unique([
            'pending',
            strtolower(
                trim((string) Order::STATUS_CONFIRMED)
            ),
        ]));
    }

    private function defaultRelations(): array
    {
        return [
            'user:id,name,email,phone,role,status,wallet_balance',
            'orderItems.foodItem:id,name,sku,price,unit',
            'qrCode',
            'confirmedBy:id,name,email,phone,role',
            'readyBy:id,name,email,phone,role',
            'completedBy:id,name,email,phone,role',
            'cancelledBy:id,name,email,phone,role',
            'walletTransactions',
        ];
    }

    private function availableQuantity(InventoryStock $stock): int
    {
        return max(
            0,
            (int) $stock->quantity - (int) $stock->reserved_quantity
        );
    }

    private function thresholdQuantity(InventoryStock $stock): int
    {
        if ($stock->low_stock_quantity !== null) {
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
     * Keep low-stock alerts current after order sales and returns.
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

            $severity = $available <= 0
                ? LowStockAlert::SEVERITY_CRITICAL
                : (
                    $available <= max(1, (int) floor($threshold / 2))
                        ? LowStockAlert::SEVERITY_HIGH
                        : LowStockAlert::SEVERITY_MEDIUM
                );

            $foodName = $stock->foodItem?->name ?? 'Food item';
            $message = $available <= 0
                ? $foodName . ' is out of stock. Available quantity is 0.'
                : $foodName . ' is low in stock. Available quantity is ' .
                    $available . ', threshold is ' . $threshold . '.';

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
                    'alert_number' => $this->generateLowStockAlertNumber(),
                    'alert_type' => $alertType,
                    'severity' => $severity,
                    'current_quantity' => $available,
                    'threshold_quantity' => $threshold,
                    'status' => LowStockAlert::STATUS_ACTIVE,
                    'message' => $message,
                    'notes' => 'Generated automatically after an order stock change.',
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
                'resolution_notes' => 'Auto-resolved because available stock is now above the threshold.',
                'updated_by' => $userId,
            ]);
        }
    }

    private function generateOrderNumber(): string
    {
        do {
            $number = 'ORD-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (Order::where('order_number', $number)->exists());

        return $number;
    }

    private function generateWalletTransactionNumber(): string
    {
        do {
            $number = 'WTR-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
        } while (WalletTransaction::where('transaction_number', $number)->exists());

        return $number;
    }

    private function generateQrCodeNumber(): string
    {
        do {
            $number = 'QR-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
        } while (OrderQrCode::where('qr_code_number', $number)->exists());

        return $number;
    }

    private function generateQrToken(): string
    {
        do {
            $token = Str::random(64);
        } while (OrderQrCode::where('qr_token', $token)->exists());

        return $token;
    }

    private function generatePickupConfirmationNumber(): string
    {
        do {
            $number = 'PUC-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
        } while (PickupConfirmation::where('confirmation_number', $number)->exists());

        return $number;
    }

    private function generateLowStockAlertNumber(): string
    {
        do {
            $number = 'LSA-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
        } while (LowStockAlert::where('alert_number', $number)->exists());

        return $number;
    }

    private function buildQrPayload(
        Order $order,
        string $qrCodeNumber,
        string $qrToken
    ): string {
        return json_encode([
            'type' => 'smart_canteen_order_pickup',
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'qr_code_number' => $qrCodeNumber,
            'qr_token' => $qrToken,
        ], JSON_UNESCAPED_SLASHES);
    }
}