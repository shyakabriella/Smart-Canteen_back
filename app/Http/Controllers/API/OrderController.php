<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\FoodItem;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderQrCode;
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
            ->with([
                'user:id,name,email,phone,role,status,wallet_balance',
                'orderItems.foodItem:id,name,sku,price,unit',
                'qrCode',
                'confirmedBy:id,name,email,phone,role',
                'readyBy:id,name,email,phone,role',
                'completedBy:id,name,email,phone,role',
                'cancelledBy:id,name,email,phone,role',
                'walletTransactions',
            ])
            ->orderByDesc('id');

        // Students can only see their own orders
        if (!$authUser->canManageOrders()) {
            $query->where('user_id', $authUser->id);
        }

        if ($request->filled('user_id') && $authUser->canManageOrders()) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('order_status')) {
            $query->where('order_status', $request->order_status);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('pickup_status')) {
            $query->where('pickup_status', $request->pickup_status);
        }

        if ($request->filled('order_type')) {
            $query->where('order_type', $request->order_type);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('ordered_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('ordered_at', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('order_number', 'like', '%' . $request->search . '%')
                    ->orWhereHas('user', function ($userQuery) use ($request) {
                        $userQuery->where('name', 'like', '%' . $request->search . '%')
                            ->orWhere('email', 'like', '%' . $request->search . '%')
                            ->orWhere('phone', 'like', '%' . $request->search . '%');
                    });
            });
        }

        $perPage = $request->get('per_page', 20);

        $orders = $query->paginate($perPage);

        return $this->sendResponse($orders, 'Orders retrieved successfully.');
    }

    /**
     * Store order with order items, wallet payment,
     * stock reduction, stock movement, and QR code.
     */
    public function store(Request $request): JsonResponse
    {
        $authUser = $request->user();

        $validator = Validator::make($request->all(), [
            // Admin/staff can create order for another user. Student creates own order.
            'user_id' => 'nullable|exists:users,id',

            'order_type' => 'nullable|in:pickup,dine_in,takeaway',
            'payment_method' => 'nullable|in:wallet',

            'discount_amount' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',

            'customer_notes' => 'nullable|string',
            'staff_notes' => 'nullable|string',

            'items' => 'required|array|min:1',
            'items.*.food_item_id' => 'required|exists:food_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $targetUserId = $authUser->canManageOrders()
            ? ($request->user_id ?? $authUser->id)
            : $authUser->id;

        try {
            $order = DB::transaction(function () use ($request, $authUser, $targetUserId) {
                $customer = User::lockForUpdate()->findOrFail($targetUserId);

                if (!$customer->isActive()) {
                    throw new \Exception('Customer account is not active.');
                }

                $subtotal = 0;
                $preparedItems = [];

                foreach ($request->items as $itemData) {
                    $foodItem = FoodItem::query()
                        ->with('inventoryStock')
                        ->where('id', $itemData['food_item_id'])
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (!$foodItem->isActive() || !$foodItem->isAvailable()) {
                        throw new \Exception($foodItem->name . ' is not available.');
                    }

                    $stock = InventoryStock::where('food_item_id', $foodItem->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$stock) {
                        throw new \Exception('Stock record not found for ' . $foodItem->name . '.');
                    }

                    $quantity = (int) $itemData['quantity'];

                    if ($stock->quantity < $quantity) {
                        throw new \Exception(
                            'Not enough stock for ' . $foodItem->name . '. Available: ' . $stock->quantity
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

                $discount = (float) ($request->discount_amount ?? 0);
                $tax = (float) ($request->tax_amount ?? 0);
                $total = ($subtotal - $discount) + $tax;

                if ($total <= 0) {
                    throw new \Exception('Order total must be greater than zero.');
                }

                $balanceBefore = (float) $customer->wallet_balance;

                if ($total > $balanceBefore) {
                    throw new \Exception('Not enough wallet balance.');
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
                    'staff_notes' => $request->staff_notes,

                    'ordered_at' => now(),
                    'paid_at' => now(),
                    'confirmed_by' => $authUser->id,
                    'confirmed_at' => now(),
                ]);

                foreach ($preparedItems as $preparedItem) {
                    $foodItem = $preparedItem['food_item'];
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

                    $quantityBefore = $stock->quantity;
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
                        'total_cost' => $foodItem->cost_price ? $foodItem->cost_price * $quantity : null,
                        'reference_type' => 'order',
                        'reference_id' => $order->id,
                        'reference_number' => $order->order_number,
                        'reason' => 'Food order sale',
                        'notes' => 'Stock reduced after order payment',
                        'movement_date' => now(),
                        'created_by' => $authUser->id,
                        'updated_by' => $authUser->id,
                    ]);
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

                // Create QR code automatically for pickup
                $qrToken = $this->generateQrToken();
                $qrCodeNumber = $this->generateQrCodeNumber();

                OrderQrCode::create([
                    'order_id' => $order->id,
                    'user_id' => $customer->id,
                    'qr_code_number' => $qrCodeNumber,
                    'qr_token' => $qrToken,
                    'qr_payload' => $this->buildQrPayload($order, $qrCodeNumber, $qrToken),
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

            $order->load([
                'user:id,name,email,phone,role,status,wallet_balance',
                'orderItems.foodItem:id,name,sku,price,unit',
                'qrCode',
                'confirmedBy:id,name,email,phone,role',
                'walletTransactions',
            ]);

            return $this->sendCreated($order, 'Order created successfully.');
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }
    }

    /**
     * Display one order.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $authUser = $request->user();

        if (!$authUser->canManageOrders() && $order->user_id !== $authUser->id) {
            return $this->sendForbidden('You can only view your own orders.');
        }

        $order->load([
            'user:id,name,email,phone,role,status,wallet_balance',
            'orderItems.foodItem:id,name,sku,price,unit',
            'qrCode',
            'confirmedBy:id,name,email,phone,role',
            'readyBy:id,name,email,phone,role',
            'completedBy:id,name,email,phone,role',
            'cancelledBy:id,name,email,phone,role',
            'walletTransactions',
        ]);

        return $this->sendResponse($order, 'Order retrieved successfully.');
    }

    /**
     * Update order notes only.
     */
    public function update(Request $request, Order $order): JsonResponse
    {
        $authUser = $request->user();

        if (!$authUser->canManageOrders() && $order->user_id !== $authUser->id) {
            return $this->sendForbidden('You can only update your own order notes.');
        }

        if ($order->isCompleted() || $order->isCancelled()) {
            return $this->sendError('Completed or cancelled orders cannot be updated.', [], 400);
        }

        $validator = Validator::make($request->all(), [
            'customer_notes' => 'nullable|string',
            'staff_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $data = [];

        if (!$authUser->canManageOrders()) {
            $data['customer_notes'] = $request->customer_notes;
        } else {
            $data['customer_notes'] = $request->customer_notes ?? $order->customer_notes;
            $data['staff_notes'] = $request->staff_notes ?? $order->staff_notes;
        }

        $order->update($data);

        $order->load([
            'user:id,name,email,phone,role,status,wallet_balance',
            'orderItems.foodItem:id,name,sku,price,unit',
            'qrCode',
        ]);

        return $this->sendResponse($order, 'Order updated successfully.');
    }

    /**
     * Delete order.
     */
    public function destroy(Request $request, Order $order): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can delete orders.');
        }

        if ($order->isCompleted()) {
            return $this->sendError('Completed orders cannot be deleted.', [], 400);
        }

        $order->delete();

        return $this->sendResponse([], 'Order deleted successfully.');
    }

    /**
     * Mark order as preparing.
     */
    public function markPreparing(Request $request, Order $order): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can update order preparation status.');
        }

        if ($order->isCancelled() || $order->isCompleted()) {
            return $this->sendError('This order cannot be marked as preparing.', [], 400);
        }

        $order->update([
            'order_status' => Order::STATUS_PREPARING,
        ]);

        $order->load([
            'user:id,name,email,phone,role,status,wallet_balance',
            'orderItems.foodItem:id,name,sku,price,unit',
            'qrCode',
        ]);

        return $this->sendResponse($order, 'Order marked as preparing successfully.');
    }

    /**
     * Mark order as ready for pickup.
     */
    public function markReady(Request $request, Order $order): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can mark orders as ready.');
        }

        if ($order->isCancelled() || $order->isCompleted()) {
            return $this->sendError('This order cannot be marked as ready.', [], 400);
        }

        $order->update([
            'order_status' => Order::STATUS_READY,
            'pickup_status' => Order::PICKUP_READY,
            'ready_by' => $request->user()->id,
            'ready_at' => now(),
        ]);

        $order->load([
            'user:id,name,email,phone,role,status,wallet_balance',
            'orderItems.foodItem:id,name,sku,price,unit',
            'qrCode',
            'readyBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($order, 'Order marked as ready successfully.');
    }

    /**
     * Complete order after customer collects food.
     */
    public function complete(Request $request, Order $order): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can complete orders.');
        }

        if ($order->isCancelled()) {
            return $this->sendError('Cancelled order cannot be completed.', [], 400);
        }

        if ($order->isCompleted()) {
            return $this->sendError('Order is already completed.', [], 400);
        }

        if ($order->payment_status !== Order::PAYMENT_PAID) {
            return $this->sendError('Only paid orders can be completed.', [], 400);
        }

        $order->update([
            'order_status' => Order::STATUS_COMPLETED,
            'pickup_status' => Order::PICKUP_COLLECTED,
            'completed_by' => $request->user()->id,
            'completed_at' => now(),
        ]);

        $order->orderItems()->update([
            'item_status' => OrderItem::STATUS_COLLECTED,
            'updated_by' => $request->user()->id,
        ]);

        $order->load('qrCode');

        if ($order->qrCode && $order->qrCode->status === OrderQrCode::STATUS_ACTIVE) {
            $order->qrCode->update([
                'status' => OrderQrCode::STATUS_USED,
                'used_at' => now(),
                'scanned_by' => $request->user()->id,
                'scanned_at' => now(),
                'updated_by' => $request->user()->id,
            ]);
        }

        $order->load([
            'user:id,name,email,phone,role,status,wallet_balance',
            'orderItems.foodItem:id,name,sku,price,unit',
            'qrCode',
            'completedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($order, 'Order completed successfully.');
    }

    /**
     * Cancel order, refund wallet, return stock, and cancel QR code.
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        $authUser = $request->user();

        if (!$authUser->canManageOrders() && $order->user_id !== $authUser->id) {
            return $this->sendForbidden('You can only cancel your own order.');
        }

        if (!$order->canBeCancelled()) {
            return $this->sendError('This order cannot be cancelled.', [], 400);
        }

        $validator = Validator::make($request->all(), [
            'cancellation_reason' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        try {
            $order = DB::transaction(function () use ($request, $authUser, $order) {
                $order = Order::with([
                        'orderItems.foodItem',
                        'qrCode',
                    ])
                    ->lockForUpdate()
                    ->findOrFail($order->id);

                // Return stock for each order item
                foreach ($order->orderItems as $orderItem) {
                    if ($orderItem->isCancelled()) {
                        continue;
                    }

                    $stock = InventoryStock::where('food_item_id', $orderItem->food_item_id)
                        ->lockForUpdate()
                        ->first();

                    if ($stock) {
                        $quantityBefore = $stock->quantity;
                        $quantityAfter = $quantityBefore + $orderItem->quantity;

                        $stock->update([
                            'quantity' => $quantityAfter,
                            'updated_by' => $authUser->id,
                        ]);

                        StockMovement::create([
                            'inventory_stock_id' => $stock->id,
                            'food_item_id' => $orderItem->food_item_id,
                            'movement_type' => StockMovement::TYPE_RETURN,
                            'quantity_before' => $quantityBefore,
                            'quantity_change' => $orderItem->quantity,
                            'quantity_after' => $quantityAfter,
                            'unit_cost' => $orderItem->cost_price,
                            'total_cost' => $orderItem->cost_price
                                ? $orderItem->cost_price * $orderItem->quantity
                                : null,
                            'reference_type' => 'order_cancel',
                            'reference_id' => $order->id,
                            'reference_number' => $order->order_number,
                            'reason' => 'Order cancelled',
                            'notes' => $request->cancellation_reason,
                            'movement_date' => now(),
                            'created_by' => $authUser->id,
                            'updated_by' => $authUser->id,
                        ]);
                    }

                    $orderItem->update([
                        'item_status' => OrderItem::STATUS_CANCELLED,
                        'updated_by' => $authUser->id,
                    ]);
                }

                // Refund wallet payment
                if (
                    $order->payment_method === Order::PAYMENT_METHOD_WALLET &&
                    $order->payment_status === Order::PAYMENT_PAID
                ) {
                    $customer = User::lockForUpdate()->findOrFail($order->user_id);

                    $balanceBefore = (float) $customer->wallet_balance;
                    $refundAmount = (float) $order->paid_amount;
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
                        'source_id' => $order->id,
                        'reference_number' => $order->order_number,
                        'amount' => $refundAmount,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceAfter,
                        'status' => WalletTransaction::STATUS_COMPLETED,
                        'description' => 'Order payment refund',
                        'notes' => $request->cancellation_reason,
                        'processed_by' => $authUser->id,
                        'processed_at' => now(),
                    ]);

                    $order->payment_status = Order::PAYMENT_REFUNDED;
                }

                // Cancel QR code
                if ($order->qrCode && !$order->qrCode->isUsed()) {
                    $order->qrCode->update([
                        'status' => OrderQrCode::STATUS_CANCELLED,
                        'cancelled_by' => $authUser->id,
                        'cancelled_at' => now(),
                        'notes' => $request->cancellation_reason,
                        'updated_by' => $authUser->id,
                    ]);
                }

                $order->order_status = Order::STATUS_CANCELLED;
                $order->pickup_status = Order::PICKUP_CANCELLED;
                $order->cancelled_by = $authUser->id;
                $order->cancelled_at = now();
                $order->cancellation_reason = $request->cancellation_reason;
                $order->save();

                return $order;
            });

            $order->load([
                'user:id,name,email,phone,role,status,wallet_balance',
                'orderItems.foodItem:id,name,sku,price,unit',
                'qrCode',
                'cancelledBy:id,name,email,phone,role',
                'walletTransactions',
            ]);

            return $this->sendResponse(
                $order,
                'Order cancelled, wallet refunded, stock returned, and QR code cancelled successfully.'
            );
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }
    }

    /**
     * Restore deleted order.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can restore orders.');
        }

        $order = Order::onlyTrashed()->find($id);

        if (!$order) {
            return $this->sendNotFound('Deleted order not found.');
        }

        $order->restore();

        $order->load([
            'user:id,name,email,phone,role,status,wallet_balance',
            'orderItems.foodItem:id,name,sku,price,unit',
            'qrCode',
        ]);

        return $this->sendResponse($order, 'Order restored successfully.');
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

        $totalOrders = (clone $query)->count();

        $paidOrders = (clone $query)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->count();

        $completedOrders = (clone $query)
            ->where('order_status', Order::STATUS_COMPLETED)
            ->count();

        $cancelledOrders = (clone $query)
            ->where('order_status', Order::STATUS_CANCELLED)
            ->count();

        $totalSales = (clone $query)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->sum('paid_amount');

        $refundedAmount = (clone $query)
            ->where('payment_status', Order::PAYMENT_REFUNDED)
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
            'paid_orders' => $paidOrders,
            'completed_orders' => $completedOrders,
            'cancelled_orders' => $cancelledOrders,
            'total_sales' => $totalSales,
            'refunded_amount' => $refundedAmount,
            'by_status' => $byStatus,
            'by_payment_status' => $byPaymentStatus,
        ], 'Order summary retrieved successfully.');
    }

    /**
     * Generate unique order number.
     */
    private function generateOrderNumber(): string
    {
        do {
            $number = 'ORD-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (Order::where('order_number', $number)->exists());

        return $number;
    }

    /**
     * Generate unique wallet transaction number.
     */
    private function generateWalletTransactionNumber(): string
    {
        do {
            $number = 'WTR-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
        } while (WalletTransaction::where('transaction_number', $number)->exists());

        return $number;
    }

    /**
     * Generate unique QR code number.
     */
    private function generateQrCodeNumber(): string
    {
        do {
            $number = 'QR-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
        } while (OrderQrCode::where('qr_code_number', $number)->exists());

        return $number;
    }

    /**
     * Generate secure QR token.
     */
    private function generateQrToken(): string
    {
        do {
            $token = Str::random(64);
        } while (OrderQrCode::where('qr_token', $token)->exists());

        return $token;
    }

    /**
     * Build QR payload.
     *
     * Frontend/mobile app will convert this payload into QR image.
     */
    private function buildQrPayload(Order $order, string $qrCodeNumber, string $qrToken): string
    {
        return json_encode([
            'type' => 'smart_canteen_order_pickup',
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'qr_code_number' => $qrCodeNumber,
            'qr_token' => $qrToken,
        ]);
    }
}