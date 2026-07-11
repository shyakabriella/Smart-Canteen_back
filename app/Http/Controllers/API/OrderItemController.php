<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrderItemController extends BaseController
{
    /**
     * Display order items.
     */
    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();

        $query = OrderItem::query()
            ->with([
                'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
                'order.user:id,name,email,phone,role',
                'foodItem:id,name,sku,price,unit',
                'createdBy:id,name,email,phone,role',
                'updatedBy:id,name,email,phone,role',
            ])
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
            $query->where(function ($q) use ($request) {
                $q->where('food_name', 'like', '%' . $request->search . '%')
                    ->orWhere('food_sku', 'like', '%' . $request->search . '%')
                    ->orWhereHas('order', function ($orderQuery) use ($request) {
                        $orderQuery->where('order_number', 'like', '%' . $request->search . '%');
                    });
            });
        }

        $perPage = $request->get('per_page', 20);

        $items = $query->paginate($perPage);

        return $this->sendResponse($items, 'Order items retrieved successfully.');
    }

    /**
     * Display one order item.
     */
    public function show(Request $request, OrderItem $orderItem): JsonResponse
    {
        $authUser = $request->user();

        $orderItem->load('order');

        if (!$authUser->canManageOrders() && $orderItem->order->user_id !== $authUser->id) {
            return $this->sendForbidden('You can only view your own order items.');
        }

        $orderItem->load([
            'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
            'order.user:id,name,email,phone,role',
            'foodItem:id,name,sku,price,unit',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($orderItem, 'Order item retrieved successfully.');
    }

    /**
     * Update order item notes/status.
     *
     * Quantity and price are not updated here because they affect payment and stock.
     */
    public function update(Request $request, OrderItem $orderItem): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can update order items.');
        }

        $validator = Validator::make($request->all(), [
            'item_status' => 'nullable|in:pending,confirmed,prepared,collected,cancelled',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $orderItem->update([
            'item_status' => $request->item_status ?? $orderItem->item_status,
            'notes' => $request->notes ?? $orderItem->notes,
            'updated_by' => $request->user()->id,
        ]);

        $orderItem->load([
            'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
            'foodItem:id,name,sku,price,unit',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($orderItem, 'Order item updated successfully.');
    }

    /**
     * Delete order item history.
     *
     * This does not return stock. Use order cancellation for stock return.
     */
    public function destroy(Request $request, OrderItem $orderItem): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can delete order items.');
        }

        $orderItem->delete();

        return $this->sendResponse([], 'Order item deleted successfully.');
    }

    /**
     * Restore deleted order item.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can restore order items.');
        }

        $item = OrderItem::onlyTrashed()->find($id);

        if (!$item) {
            return $this->sendNotFound('Deleted order item not found.');
        }

        $item->restore();

        $item->load([
            'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
            'foodItem:id,name,sku,price,unit',
        ]);

        return $this->sendResponse($item, 'Order item restored successfully.');
    }
}