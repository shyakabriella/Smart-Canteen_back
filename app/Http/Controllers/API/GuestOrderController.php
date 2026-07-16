<?php

namespace App\Http\Controllers\API;

use App\Models\FoodCategory;
use App\Models\FoodItem;
use App\Models\GuestOrder;
use App\Models\GuestOrderItem;
use App\Models\InventoryStock;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GuestOrderController extends BaseController
{
    /**
     * Public website: create an unpaid delivery order.
     *
     * No authentication, wallet deduction or QR generation.
     * Stock is checked here but deducted only when staff confirms.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make(
            $request->all(),
            [
                'customer_name' => [
                    'required',
                    'string',
                    'max:255',
                ],
                'customer_email' => [
                    'required',
                    'email',
                    'max:255',
                ],
                'customer_phone' => [
                    'required',
                    'string',
                    'max:50',
                ],
                'delivery_location' => [
                    'required',
                    'string',
                    'max:1500',
                ],
                'preferred_delivery_time' => [
                    'nullable',
                    'string',
                    'max:100',
                ],
                'customer_notes' => [
                    'nullable',
                    'string',
                    'max:2000',
                ],
                'items' => [
                    'required',
                    'array',
                    'min:1',
                    'max:30',
                ],
                'items.*.food_item_id' => [
                    'required',
                    'integer',
                    'distinct',
                    Rule::exists(
                        'food_items',
                        'id'
                    )->whereNull(
                        'deleted_at'
                    ),
                ],
                'items.*.quantity' => [
                    'required',
                    'integer',
                    'min:1',
                    'max:100',
                ],
                'items.*.notes' => [
                    'nullable',
                    'string',
                    'max:1000',
                ],
            ]
        );

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your delivery order information.'
            );
        }

        try {
            $guestOrder = DB::transaction(
                function () use ($request) {
                    $subtotal = 0.0;
                    $preparedItems = [];

                    foreach (
                        $request->items
                        as $itemData
                    ) {
                        $foodItem = FoodItem::query()
                            ->with(
                                'category:id,status'
                            )
                            ->whereKey(
                                $itemData[
                                    'food_item_id'
                                ]
                            )
                            ->lockForUpdate()
                            ->firstOrFail();

                        if (
                            !$foodItem->isActive() ||
                            !$foodItem->isAvailable() ||
                            !$foodItem->category ||
                            $foodItem->category->status !==
                                FoodCategory::STATUS_ACTIVE
                        ) {
                            throw new \RuntimeException(
                                $foodItem->name .
                                ' is not available.'
                            );
                        }

                        $stock = InventoryStock::query()
                            ->where(
                                'food_item_id',
                                $foodItem->id
                            )
                            ->lockForUpdate()
                            ->first();

                        if (!$stock) {
                            throw new \RuntimeException(
                                'Stock record not found for ' .
                                $foodItem->name .
                                '.'
                            );
                        }

                        if (
                            $stock->status !==
                            InventoryStock::STATUS_ACTIVE
                        ) {
                            throw new \RuntimeException(
                                'Stock is inactive for ' .
                                $foodItem->name .
                                '.'
                            );
                        }

                        $quantity = (int)
                            $itemData['quantity'];

                        $available = $this
                            ->availableQuantity(
                                $stock
                            );

                        if (
                            $available <
                            $quantity
                        ) {
                            throw new \RuntimeException(
                                'Not enough available stock for ' .
                                $foodItem->name .
                                '. Available: ' .
                                $available .
                                '.'
                            );
                        }

                        $unitPrice = (float)
                            $foodItem->price;

                        $lineTotal =
                            $unitPrice *
                            $quantity;

                        $subtotal +=
                            $lineTotal;

                        $preparedItems[] = [
                            'food_item' =>
                                $foodItem,
                            'quantity' =>
                                $quantity,
                            'unit_price' =>
                                $unitPrice,
                            'line_total' =>
                                $lineTotal,
                            'notes' =>
                                $itemData[
                                    'notes'
                                ] ?? null,
                        ];
                    }

                    if ($subtotal <= 0) {
                        throw new \RuntimeException(
                            'Order total must be greater than zero.'
                        );
                    }

                    $guestOrder =
                        GuestOrder::create([
                            'order_number' =>
                                $this
                                    ->generateOrderNumber(),
                            'public_token' =>
                                $this
                                    ->generatePublicToken(),
                            'customer_name' =>
                                trim(
                                    (string)
                                    $request
                                        ->customer_name
                                ),
                            'customer_email' =>
                                strtolower(
                                    trim(
                                        (string)
                                        $request
                                            ->customer_email
                                    )
                                ),
                            'customer_phone' =>
                                trim(
                                    (string)
                                    $request
                                        ->customer_phone
                                ),
                            'delivery_location' =>
                                trim(
                                    (string)
                                    $request
                                        ->delivery_location
                                ),
                            'preferred_delivery_time' =>
                                $request
                                    ->preferred_delivery_time,
                            'subtotal_amount' =>
                                $subtotal,
                            'discount_amount' =>
                                0,
                            'tax_amount' =>
                                0,
                            'total_amount' =>
                                $subtotal,
                            'paid_amount' =>
                                0,
                            'payment_method' =>
                                'pay_on_delivery',
                            'payment_status' =>
                                GuestOrder::PAYMENT_PENDING,
                            'order_status' =>
                                GuestOrder::STATUS_PENDING,
                            'delivery_status' =>
                                GuestOrder::DELIVERY_PENDING,
                            'customer_notes' =>
                                $request
                                    ->customer_notes,
                        ]);

                    foreach (
                        $preparedItems
                        as $preparedItem
                    ) {
                        $foodItem =
                            $preparedItem[
                                'food_item'
                            ];

                        GuestOrderItem::create([
                            'guest_order_id' =>
                                $guestOrder->id,
                            'food_item_id' =>
                                $foodItem->id,
                            'food_name' =>
                                $foodItem->name,
                            'food_sku' =>
                                $foodItem->sku,
                            'unit' =>
                                $foodItem->unit,
                            'quantity' =>
                                $preparedItem[
                                    'quantity'
                                ],
                            'unit_price' =>
                                $preparedItem[
                                    'unit_price'
                                ],
                            'subtotal_amount' =>
                                $preparedItem[
                                    'line_total'
                                ],
                            'total_amount' =>
                                $preparedItem[
                                    'line_total'
                                ],
                            'item_status' =>
                                'pending',
                            'notes' =>
                                $preparedItem[
                                    'notes'
                                ],
                        ]);
                    }

                    return $guestOrder;
                }
            );

            $guestOrder->load(
                $this->relations()
            );

            return $this->sendCreated(
                [
                    'id' =>
                        $guestOrder->id,
                    'order_number' =>
                        $guestOrder
                            ->order_number,
                    'public_token' =>
                        $guestOrder
                            ->getRawOriginal(
                                'public_token'
                            ),
                    'order_status' =>
                        $guestOrder
                            ->order_status,
                    'payment_method' =>
                        $guestOrder
                            ->payment_method,
                    'payment_status' =>
                        $guestOrder
                            ->payment_status,
                    'delivery_status' =>
                        $guestOrder
                            ->delivery_status,
                    'total_amount' =>
                        (float)
                        $guestOrder
                            ->total_amount,
                    'paid_amount' =>
                        (float)
                        $guestOrder
                            ->paid_amount,
                    'amount_due' =>
                        (float)
                        $guestOrder
                            ->total_amount,
                    'customer_name' =>
                        $guestOrder
                            ->customer_name,
                    'customer_email' =>
                        $guestOrder
                            ->customer_email,
                    'customer_phone' =>
                        $guestOrder
                            ->customer_phone,
                    'delivery_location' =>
                        $guestOrder
                            ->delivery_location,
                    'preferred_delivery_time' =>
                        $guestOrder
                            ->preferred_delivery_time,
                    'items' =>
                        $guestOrder->items,
                ],
                'Delivery order received. Payment is due on delivery.'
            );
        } catch (\Throwable $e) {
            return $this->sendError(
                $e->getMessage(),
                [],
                400
            );
        }
    }

    /**
     * Public customer tracking using the secret token.
     */
    public function track(
        string $publicToken
    ): JsonResponse {
        $guestOrder = GuestOrder::query()
            ->where(
                'public_token',
                $publicToken
            )
            ->with([
                'items:id,guest_order_id,food_name,unit,quantity,unit_price,total_amount,item_status',
            ])
            ->first();

        if (!$guestOrder) {
            return $this->sendNotFound(
                'Delivery order not found.'
            );
        }

        return $this->sendResponse(
            [
                'order_number' =>
                    $guestOrder
                        ->order_number,
                'order_status' =>
                    $guestOrder
                        ->order_status,
                'payment_status' =>
                    $guestOrder
                        ->payment_status,
                'payment_method' =>
                    $guestOrder
                        ->payment_method,
                'delivery_status' =>
                    $guestOrder
                        ->delivery_status,
                'total_amount' =>
                    (float)
                    $guestOrder
                        ->total_amount,
                'paid_amount' =>
                    (float)
                    $guestOrder
                        ->paid_amount,
                'amount_due' =>
                    max(
                        0,
                        (float)
                        $guestOrder
                            ->total_amount -
                        (float)
                        $guestOrder
                            ->paid_amount
                    ),
                'customer_name' =>
                    $guestOrder
                        ->customer_name,
                'delivery_location' =>
                    $guestOrder
                        ->delivery_location,
                'preferred_delivery_time' =>
                    $guestOrder
                        ->preferred_delivery_time,
                'items' =>
                    $guestOrder->items,
                'created_at' =>
                    $guestOrder
                        ->created_at,
            ],
            'Delivery order retrieved successfully.'
        );
    }

    /**
     * Admin and staff guest order list.
     */
    public function index(
        Request $request
    ): JsonResponse {
        if (
            !$request
                ->user()
                ->canManageOrders()
        ) {
            return $this->sendForbidden(
                'Only admin or staff can view guest orders.'
            );
        }

        $query = GuestOrder::query()
            ->with($this->relations())
            ->orderByDesc('id');

        foreach ([
            'order_status',
            'payment_status',
            'delivery_status',
        ] as $field) {
            if (
                $request->filled(
                    $field
                )
            ) {
                $query->where(
                    $field,
                    $request->input(
                        $field
                    )
                );
            }
        }

        if ($request->filled('search')) {
            $search = trim(
                (string)
                $request->search
            );

            $query->where(
                function ($q) use ($search) {
                    $q->where(
                        'order_number',
                        'like',
                        '%' .
                        $search .
                        '%'
                    )
                        ->orWhere(
                            'customer_name',
                            'like',
                            '%' .
                            $search .
                            '%'
                        )
                        ->orWhere(
                            'customer_email',
                            'like',
                            '%' .
                            $search .
                            '%'
                        )
                        ->orWhere(
                            'customer_phone',
                            'like',
                            '%' .
                            $search .
                            '%'
                        )
                        ->orWhere(
                            'delivery_location',
                            'like',
                            '%' .
                            $search .
                            '%'
                        );
                }
            );
        }

        $perPage = min(
            max(
                (int)
                $request->get(
                    'per_page',
                    20
                ),
                1
            ),
            200
        );

        return $this->sendResponse(
            $query->paginate(
                $perPage
            ),
            'Guest delivery orders retrieved successfully.'
        );
    }

    public function show(
        Request $request,
        GuestOrder $guestOrder
    ): JsonResponse {
        if (
            !$request
                ->user()
                ->canManageOrders()
        ) {
            return $this->sendForbidden(
                'Only admin or staff can view guest orders.'
            );
        }

        $guestOrder->load(
            $this->relations()
        );

        return $this->sendResponse(
            $guestOrder,
            'Guest delivery order retrieved successfully.'
        );
    }

    /**
     * Staff accepts the order and reserves stock.
     */
    public function confirm(
        Request $request,
        GuestOrder $guestOrder
    ): JsonResponse {
        if (
            !$request
                ->user()
                ->canManageOrders()
        ) {
            return $this->sendForbidden(
                'Only admin or staff can confirm guest orders.'
            );
        }

        try {
            $guestOrder = DB::transaction(
                function () use (
                    $request,
                    $guestOrder
                ) {
                    $order = GuestOrder::query()
                        ->with('items')
                        ->whereKey(
                            $guestOrder->id
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (
                        $order->order_status !==
                        GuestOrder::STATUS_PENDING
                    ) {
                        throw new \RuntimeException(
                            'Only pending guest orders can be confirmed.'
                        );
                    }

                    foreach (
                        $order->items
                        as $item
                    ) {
                        $stock =
                            InventoryStock::query()
                                ->where(
                                    'food_item_id',
                                    $item
                                        ->food_item_id
                                )
                                ->lockForUpdate()
                                ->first();

                        if (!$stock) {
                            throw new \RuntimeException(
                                'Stock record not found for ' .
                                $item
                                    ->food_name .
                                '.'
                            );
                        }

                        if (
                            $stock->status !==
                            InventoryStock::STATUS_ACTIVE
                        ) {
                            throw new \RuntimeException(
                                'Stock is inactive for ' .
                                $item
                                    ->food_name .
                                '.'
                            );
                        }

                        $quantity =
                            (int)
                            $item->quantity;

                        $available =
                            $this
                                ->availableQuantity(
                                    $stock
                                );

                        if (
                            $available <
                            $quantity
                        ) {
                            throw new \RuntimeException(
                                'Not enough stock for ' .
                                $item
                                    ->food_name .
                                '. Available: ' .
                                $available .
                                '.'
                            );
                        }

                        $before =
                            (int)
                            $stock
                                ->quantity;

                        $after =
                            $before -
                            $quantity;

                        $stock->update([
                            'quantity' =>
                                $after,
                            'updated_by' =>
                                $request
                                    ->user()
                                    ->id,
                        ]);

                        StockMovement::create([
                            'inventory_stock_id' =>
                                $stock->id,
                            'food_item_id' =>
                                $item
                                    ->food_item_id,
                            'movement_type' =>
                                StockMovement::TYPE_SALE,
                            'quantity_before' =>
                                $before,
                            'quantity_change' =>
                                -abs(
                                    $quantity
                                ),
                            'quantity_after' =>
                                $after,
                            'reference_type' =>
                                'guest_order',
                            'reference_id' =>
                                $order->id,
                            'reference_number' =>
                                $order
                                    ->order_number,
                            'reason' =>
                                'Guest delivery order confirmed',
                            'notes' =>
                                'Stock reserved after staff confirmation.',
                            'movement_date' =>
                                now(),
                            'created_by' =>
                                $request
                                    ->user()
                                    ->id,
                            'updated_by' =>
                                $request
                                    ->user()
                                    ->id,
                        ]);

                        $item->update([
                            'item_status' =>
                                'confirmed',
                        ]);
                    }

                    $order->update([
                        'order_status' =>
                            GuestOrder::STATUS_CONFIRMED,
                        'confirmed_by' =>
                            $request
                                ->user()
                                ->id,
                        'confirmed_at' =>
                            now(),
                    ]);

                    return $order;
                }
            );
        } catch (\Throwable $e) {
            return $this->sendError(
                $e->getMessage(),
                [],
                400
            );
        }

        $guestOrder->load(
            $this->relations()
        );

        return $this->sendResponse(
            $guestOrder,
            'Guest order confirmed and stock reserved successfully.'
        );
    }

    public function markPreparing(
        Request $request,
        GuestOrder $guestOrder
    ): JsonResponse {
        if (
            !$request
                ->user()
                ->canManageOrders()
        ) {
            return $this->sendForbidden(
                'Only admin or staff can update guest orders.'
            );
        }

        if (
            $guestOrder->order_status !==
            GuestOrder::STATUS_CONFIRMED
        ) {
            return $this->sendError(
                'Only confirmed guest orders can be marked as preparing.',
                [],
                400
            );
        }

        $guestOrder->update([
            'order_status' =>
                GuestOrder::STATUS_PREPARING,
            'delivery_status' =>
                GuestOrder::DELIVERY_PREPARING,
        ]);

        $guestOrder->load(
            $this->relations()
        );

        return $this->sendResponse(
            $guestOrder,
            'Guest order marked as preparing successfully.'
        );
    }

    public function markReady(
        Request $request,
        GuestOrder $guestOrder
    ): JsonResponse {
        if (
            !$request
                ->user()
                ->canManageOrders()
        ) {
            return $this->sendForbidden(
                'Only admin or staff can update guest orders.'
            );
        }

        if (
            $guestOrder->order_status !==
            GuestOrder::STATUS_PREPARING
        ) {
            return $this->sendError(
                'Only preparing guest orders can be marked as ready.',
                [],
                400
            );
        }

        $guestOrder->update([
            'order_status' =>
                GuestOrder::STATUS_READY,
            'delivery_status' =>
                GuestOrder::DELIVERY_OUT_FOR_DELIVERY,
            'ready_by' =>
                $request
                    ->user()
                    ->id,
            'ready_at' =>
                now(),
        ]);

        $guestOrder->items()->update([
            'item_status' =>
                'prepared',
        ]);

        $guestOrder->load(
            $this->relations()
        );

        return $this->sendResponse(
            $guestOrder,
            'Guest order is ready for delivery.'
        );
    }

    public function completeDelivery(
        Request $request,
        GuestOrder $guestOrder
    ): JsonResponse {
        if (
            !$request
                ->user()
                ->canManageOrders()
        ) {
            return $this->sendForbidden(
                'Only admin or staff can complete deliveries.'
            );
        }

        $validator = Validator::make(
            $request->all(),
            [
                'payment_received' => [
                    'nullable',
                    'boolean',
                ],
                'notes' => [
                    'nullable',
                    'string',
                    'max:2000',
                ],
            ]
        );

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        if (
            $guestOrder->order_status !==
            GuestOrder::STATUS_READY
        ) {
            return $this->sendError(
                'Only ready guest orders can be completed.',
                [],
                400
            );
        }

        $paymentReceived =
            $request->boolean(
                'payment_received',
                true
            );

        $data = [
            'order_status' =>
                GuestOrder::STATUS_COMPLETED,
            'delivery_status' =>
                GuestOrder::DELIVERY_DELIVERED,
            'completed_by' =>
                $request
                    ->user()
                    ->id,
            'completed_at' =>
                now(),
        ];

        if ($paymentReceived) {
            $data['payment_status'] =
                GuestOrder::PAYMENT_PAID;

            $data['paid_amount'] =
                $guestOrder
                    ->total_amount;
        }

        if ($request->filled('notes')) {
            $data['staff_notes'] =
                $request->notes;
        }

        $guestOrder->update($data);

        $guestOrder->items()->update([
            'item_status' =>
                'collected',
        ]);

        $guestOrder->load(
            $this->relations()
        );

        return $this->sendResponse(
            $guestOrder,
            $paymentReceived
                ? 'Delivery completed and payment recorded.'
                : 'Delivery completed. Payment remains pending.'
        );
    }

    public function cancel(
        Request $request,
        GuestOrder $guestOrder
    ): JsonResponse {
        if (
            !$request
                ->user()
                ->canManageOrders()
        ) {
            return $this->sendForbidden(
                'Only admin or staff can cancel guest orders.'
            );
        }

        $validator = Validator::make(
            $request->all(),
            [
                'cancellation_reason' => [
                    'nullable',
                    'string',
                    'max:2000',
                ],
            ]
        );

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        try {
            $guestOrder = DB::transaction(
                function () use (
                    $request,
                    $guestOrder
                ) {
                    $order = GuestOrder::query()
                        ->with('items')
                        ->whereKey(
                            $guestOrder->id
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (
                        in_array(
                            $order->order_status,
                            [
                                GuestOrder::STATUS_COMPLETED,
                                GuestOrder::STATUS_CANCELLED,
                            ],
                            true
                        )
                    ) {
                        throw new \RuntimeException(
                            'This guest order cannot be cancelled.'
                        );
                    }

                    $stockWasReserved =
                        in_array(
                            $order->order_status,
                            [
                                GuestOrder::STATUS_CONFIRMED,
                                GuestOrder::STATUS_PREPARING,
                                GuestOrder::STATUS_READY,
                            ],
                            true
                        );

                    if ($stockWasReserved) {
                        foreach (
                            $order->items
                            as $item
                        ) {
                            $stock =
                                InventoryStock::query()
                                    ->where(
                                        'food_item_id',
                                        $item
                                            ->food_item_id
                                    )
                                    ->lockForUpdate()
                                    ->first();

                            if (!$stock) {
                                continue;
                            }

                            $before =
                                (int)
                                $stock
                                    ->quantity;

                            $returned =
                                (int)
                                $item
                                    ->quantity;

                            $after =
                                $before +
                                $returned;

                            $stock->update([
                                'quantity' =>
                                    $after,
                                'updated_by' =>
                                    $request
                                        ->user()
                                        ->id,
                            ]);

                            StockMovement::create([
                                'inventory_stock_id' =>
                                    $stock->id,
                                'food_item_id' =>
                                    $item
                                        ->food_item_id,
                                'movement_type' =>
                                    StockMovement::TYPE_RETURN,
                                'quantity_before' =>
                                    $before,
                                'quantity_change' =>
                                    $returned,
                                'quantity_after' =>
                                    $after,
                                'reference_type' =>
                                    'guest_order_cancel',
                                'reference_id' =>
                                    $order->id,
                                'reference_number' =>
                                    $order
                                        ->order_number,
                                'reason' =>
                                    'Guest delivery order cancelled',
                                'notes' =>
                                    $request
                                        ->cancellation_reason,
                                'movement_date' =>
                                    now(),
                                'created_by' =>
                                    $request
                                        ->user()
                                        ->id,
                                'updated_by' =>
                                    $request
                                        ->user()
                                        ->id,
                            ]);
                        }
                    }

                    $order->items()->update([
                        'item_status' =>
                            'cancelled',
                    ]);

                    $order->update([
                        'order_status' =>
                            GuestOrder::STATUS_CANCELLED,
                        'delivery_status' =>
                            GuestOrder::DELIVERY_CANCELLED,
                        'payment_status' =>
                            GuestOrder::PAYMENT_CANCELLED,
                        'cancelled_by' =>
                            $request
                                ->user()
                                ->id,
                        'cancelled_at' =>
                            now(),
                        'cancellation_reason' =>
                            $request
                                ->cancellation_reason,
                    ]);

                    return $order;
                }
            );
        } catch (\Throwable $e) {
            return $this->sendError(
                $e->getMessage(),
                [],
                400
            );
        }

        $guestOrder->load(
            $this->relations()
        );

        return $this->sendResponse(
            $guestOrder,
            'Guest delivery order cancelled successfully.'
        );
    }

    private function relations(): array
    {
        return [
            'items.foodItem:id,name,sku,price,unit',
            'confirmedBy:id,name,email,phone,role',
            'readyBy:id,name,email,phone,role',
            'completedBy:id,name,email,phone,role',
            'cancelledBy:id,name,email,phone,role',
        ];
    }

    private function availableQuantity(
        InventoryStock $stock
    ): int {
        return max(
            0,
            (int) $stock->quantity -
            (int) $stock->reserved_quantity
        );
    }

    private function generateOrderNumber(): string
    {
        do {
            $number =
                'WEB-' .
                now()->format(
                    'Ymd'
                ) .
                '-' .
                strtoupper(
                    Str::random(6)
                );
        } while (
            GuestOrder::where(
                'order_number',
                $number
            )->exists()
        );

        return $number;
    }

    private function generatePublicToken(): string
    {
        do {
            $token =
                Str::random(64);
        } while (
            GuestOrder::where(
                'public_token',
                $token
            )->exists()
        );

        return $token;
    }
}
