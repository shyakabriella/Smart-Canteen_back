<?php

namespace App\Http\Controllers\API;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class OrderItemController extends BaseController
{
    /**
     * Display order items.
     */
    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();

        $query = OrderItem::query()
            ->with($this->defaultRelations())
            ->orderByDesc('id');

        if (!$authUser->canManageOrders()) {
            $query->whereHas('order', function ($q) use ($authUser) {
                $q->where('user_id', $authUser->id);
            });
        }

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->order_id);
        }

        if ($request->filled('food_item_id')) {
            $query->where('food_item_id', $request->food_item_id);
        }

        if ($request->filled('item_status')) {
            $query->where('item_status', $request->item_status);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                $q->where('food_name', 'like', '%' . $search . '%')
                    ->orWhere('food_sku', 'like', '%' . $search . '%')
                    ->orWhereHas('order', function ($orderQuery) use ($search) {
                        $orderQuery->where(
                            'order_number',
                            'like',
                            '%' . $search . '%'
                        );
                    });
            });
        }

        $perPage = min(max((int) $request->get('per_page', 20), 1), 200);
        $items = $query->paginate($perPage);

        return $this->sendResponse(
            $items,
            'Order items retrieved successfully.'
        );
    }

    /**
     * Display one order item.
     */
    public function show(
        Request $request,
        OrderItem $orderItem
    ): JsonResponse {
        $authUser = $request->user();

        $orderItem->load('order');

        if (
            !$authUser->canManageOrders() &&
            $orderItem->order->user_id !== $authUser->id
        ) {
            return $this->sendForbidden(
                'You can only view your own order items.'
            );
        }

        $orderItem->load($this->defaultRelations());

        return $this->sendResponse(
            $orderItem,
            'Order item retrieved successfully.'
        );
    }

    /**
     * Update order-item notes and synchronized status.
     *
     * Quantity and prices are intentionally immutable because they affect
     * payment, wallet balance, stock, and financial history.
     */
    public function update(
        Request $request,
        OrderItem $orderItem
    ): JsonResponse {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden(
                'Only admin or staff can update order items.'
            );
        }

        $validator = Validator::make($request->all(), [
            'item_status' => [
                'sometimes',
                'required',
                Rule::in([
                    'pending',
                    'confirmed',
                    'prepared',
                    'collected',
                    'cancelled',
                ]),
            ],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        $orderItem->load('order');

        if ($request->has('item_status')) {
            $statusError = $this->validateStatusAgainstOrder(
                $orderItem->order,
                (string) $request->item_status
            );

            if ($statusError !== null) {
                return $this->sendError($statusError, [], 400);
            }
        }

        $data = ['updated_by' => $request->user()->id];

        if ($request->has('item_status')) {
            $data['item_status'] = $request->item_status;
        }

        if ($request->has('notes')) {
            $data['notes'] = $request->notes;
        }

        $orderItem->update($data);
        $orderItem->load($this->defaultRelations());

        return $this->sendResponse(
            $orderItem,
            'Order item updated successfully.'
        );
    }

    /**
     * Delete an order item only after its parent order is cancelled.
     *
     * Active order items are retained to protect payment and stock history.
     */
    public function destroy(
        Request $request,
        OrderItem $orderItem
    ): JsonResponse {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden(
                'Only admin or staff can delete order items.'
            );
        }

        $orderItem->load('order');

        if (!$orderItem->order->isCancelled()) {
            return $this->sendError(
                'Cancel the parent order before deleting an order item.',
                [],
                400
            );
        }

        $orderItem->delete();

        return $this->sendResponse(
            [],
            'Order item deleted successfully.'
        );
    }

    /**
     * Restore a deleted order item.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden(
                'Only admin or staff can restore order items.'
            );
        }

        $item = OrderItem::onlyTrashed()->find($id);

        if (!$item) {
            return $this->sendNotFound(
                'Deleted order item not found.'
            );
        }

        $item->restore();
        $item->load($this->defaultRelations());

        return $this->sendResponse(
            $item,
            'Order item restored successfully.'
        );
    }

    /**
     * Ensure an item status agrees with its parent order status.
     */
    private function validateStatusAgainstOrder(
        Order $order,
        string $itemStatus
    ): ?string {
        $allowedStatuses = match ($order->order_status) {
            Order::STATUS_CONFIRMED,
            Order::STATUS_PREPARING => ['pending', 'confirmed'],

            Order::STATUS_READY => ['prepared'],
            Order::STATUS_COMPLETED => ['collected'],
            Order::STATUS_CANCELLED => ['cancelled'],
            default => [],
        };

        if (!in_array($itemStatus, $allowedStatuses, true)) {
            return 'The item status does not match the parent order status.';
        }

        return null;
    }

    /**
     * Default order-item relations.
     */
    private function defaultRelations(): array
    {
        return [
            'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
            'order.user:id,name,email,phone,role',
            'foodItem:id,name,sku,price,unit',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ];
    }
}