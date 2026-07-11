<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderQrCode;
use App\Models\PickupConfirmation;
use App\Models\QrScanLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class OrderQrCodeController extends BaseController
{
    /**
     * Display order QR codes.
     */
    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();

        $query = OrderQrCode::query()
            ->with([
                'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
                'user:id,name,email,phone,role,status',
                'scanLogs:id,order_qr_code_id,scan_action,scan_status,scanned_by,scanned_at,message',
                'pickupConfirmation:id,order_id,order_qr_code_id,confirmation_number,status,confirmed_by,confirmed_at',
                'scannedBy:id,name,email,phone,role',
                'cancelledBy:id,name,email,phone,role',
                'createdBy:id,name,email,phone,role',
                'updatedBy:id,name,email,phone,role',
            ])
            ->orderByDesc('id');

        // Student/user can only see their own QR codes
        if (!$authUser->canManageOrders()) {
            $query->where('user_id', $authUser->id);
        }

        if ($request->filled('user_id') && $authUser->canManageOrders()) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->order_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('qr_code_number', 'like', '%' . $request->search . '%')
                    ->orWhere('qr_token', 'like', '%' . $request->search . '%')
                    ->orWhereHas('order', function ($orderQuery) use ($request) {
                        $orderQuery->where('order_number', 'like', '%' . $request->search . '%');
                    })
                    ->orWhereHas('user', function ($userQuery) use ($request) {
                        $userQuery->where('name', 'like', '%' . $request->search . '%')
                            ->orWhere('email', 'like', '%' . $request->search . '%')
                            ->orWhere('phone', 'like', '%' . $request->search . '%');
                    });
            });
        }

        $perPage = $request->get('per_page', 20);

        $qrCodes = $query->paginate($perPage);

        return $this->sendResponse($qrCodes, 'Order QR codes retrieved successfully.');
    }

    /**
     * Create QR code for a paid order.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can create order QR codes.');
        }

        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,id|unique:order_qr_codes,order_id',
            'expires_at' => 'nullable|date|after:now',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $order = Order::findOrFail($request->order_id);

        if ($order->payment_status !== Order::PAYMENT_PAID) {
            return $this->sendError('QR code can only be created for paid orders.', [], 400);
        }

        if ($order->isCancelled()) {
            return $this->sendError('QR code cannot be created for cancelled orders.', [], 400);
        }

        if ($order->isCompleted()) {
            return $this->sendError('QR code cannot be created for completed orders.', [], 400);
        }

        $qrToken = $this->generateQrToken();
        $qrCodeNumber = $this->generateQrCodeNumber();

        $qrCode = OrderQrCode::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'qr_code_number' => $qrCodeNumber,
            'qr_token' => $qrToken,
            'qr_payload' => $this->buildQrPayload($order, $qrCodeNumber, $qrToken),
            'qr_image' => null,
            'status' => OrderQrCode::STATUS_ACTIVE,
            'expires_at' => $request->expires_at ?? now()->addDay(),
            'used_at' => null,
            'scanned_by' => null,
            'scanned_at' => null,
            'cancelled_by' => null,
            'cancelled_at' => null,
            'notes' => $request->notes,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $qrCode->load([
            'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
            'user:id,name,email,phone,role,status',
            'createdBy:id,name,email,phone,role',
        ]);

        return $this->sendCreated($qrCode, 'Order QR code created successfully.');
    }

    /**
     * Display one order QR code.
     */
    public function show(Request $request, OrderQrCode $orderQrCode): JsonResponse
    {
        $authUser = $request->user();

        if (!$authUser->canManageOrders() && $orderQrCode->user_id !== $authUser->id) {
            return $this->sendForbidden('You can only view your own order QR codes.');
        }

        $orderQrCode->load([
            'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
            'order.orderItems.foodItem:id,name,sku,price,unit',
            'user:id,name,email,phone,role,status',
            'scanLogs.scannedBy:id,name,email,phone,role',
            'pickupConfirmation.confirmedBy:id,name,email,phone,role',
            'scannedBy:id,name,email,phone,role',
            'cancelledBy:id,name,email,phone,role',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($orderQrCode, 'Order QR code retrieved successfully.');
    }

    /**
     * Verify QR code by token or QR code number.
     */
    public function verify(Request $request): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can verify order QR codes.');
        }

        $validator = Validator::make($request->all(), [
            'qr_token' => 'nullable|string',
            'qr_code_number' => 'nullable|string',
            'scanned_payload' => 'nullable|string',
            'device_name' => 'nullable|string|max:255',
            'device_type' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        if (!$request->filled('qr_token') && !$request->filled('qr_code_number')) {
            $this->createScanLog(
                $request,
                null,
                QrScanLog::ACTION_VERIFY,
                QrScanLog::STATUS_FAILED,
                'QR token or QR code number is required.',
                'Missing QR input.'
            );

            return $this->sendError('QR token or QR code number is required.', [], 422);
        }

        $qrCode = $this->findQrCode($request);

        if (!$qrCode) {
            $this->createScanLog(
                $request,
                null,
                QrScanLog::ACTION_VERIFY,
                QrScanLog::STATUS_INVALID,
                'Order QR code not found.',
                'Invalid QR code.'
            );

            return $this->sendNotFound('Order QR code not found.');
        }

        $qrCode->load([
            'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
            'order.orderItems.foodItem:id,name,sku,price,unit',
            'user:id,name,email,phone,role,status',
            'pickupConfirmation:id,order_id,order_qr_code_id,confirmation_number,status',
        ]);

        if ($qrCode->pickupConfirmation) {
            $this->createScanLog(
                $request,
                $qrCode,
                QrScanLog::ACTION_VERIFY,
                QrScanLog::STATUS_USED,
                'Pickup has already been confirmed for this QR code.',
                'Pickup already confirmed.'
            );

            return $this->sendError('Pickup has already been confirmed for this QR code.', [], 400);
        }

        if ($qrCode->isCancelled()) {
            $this->createScanLog(
                $request,
                $qrCode,
                QrScanLog::ACTION_VERIFY,
                QrScanLog::STATUS_CANCELLED,
                'This QR code has been cancelled.',
                'Cancelled QR code.'
            );

            return $this->sendError('This QR code has been cancelled.', [], 400);
        }

        if ($qrCode->isUsed()) {
            $this->createScanLog(
                $request,
                $qrCode,
                QrScanLog::ACTION_VERIFY,
                QrScanLog::STATUS_USED,
                'This QR code has already been used.',
                'Duplicate QR scan.'
            );

            return $this->sendError('This QR code has already been used.', [], 400);
        }

        if ($qrCode->isExpired()) {
            $qrCode->update([
                'status' => OrderQrCode::STATUS_EXPIRED,
                'updated_by' => $request->user()->id,
            ]);

            $this->createScanLog(
                $request,
                $qrCode,
                QrScanLog::ACTION_VERIFY,
                QrScanLog::STATUS_EXPIRED,
                'This QR code has expired.',
                'Expired QR code.'
            );

            return $this->sendError('This QR code has expired.', [], 400);
        }

        if ($qrCode->order->payment_status !== Order::PAYMENT_PAID) {
            $this->createScanLog(
                $request,
                $qrCode,
                QrScanLog::ACTION_VERIFY,
                QrScanLog::STATUS_UNPAID,
                'This order is not paid.',
                'Unpaid order.'
            );

            return $this->sendError('This order is not paid.', [], 400);
        }

        if ($qrCode->order->order_status === Order::STATUS_CANCELLED) {
            $this->createScanLog(
                $request,
                $qrCode,
                QrScanLog::ACTION_VERIFY,
                QrScanLog::STATUS_CANCELLED,
                'This order has been cancelled.',
                'Cancelled order.'
            );

            return $this->sendError('This order has been cancelled.', [], 400);
        }

        if ($qrCode->order->order_status === Order::STATUS_COMPLETED) {
            $this->createScanLog(
                $request,
                $qrCode,
                QrScanLog::ACTION_VERIFY,
                QrScanLog::STATUS_USED,
                'This order has already been completed.',
                'Completed order.'
            );

            return $this->sendError('This order has already been completed.', [], 400);
        }

        $this->createScanLog(
            $request,
            $qrCode,
            QrScanLog::ACTION_VERIFY,
            QrScanLog::STATUS_VALID,
            'QR code verified successfully.',
            null
        );

        return $this->sendResponse($qrCode, 'QR code verified successfully.');
    }

    /**
     * Mark QR code as used and create official pickup confirmation.
     */
    public function markUsed(Request $request, OrderQrCode $orderQrCode): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can mark QR code as used.');
        }

        $validator = Validator::make($request->all(), [
            'device_name' => 'nullable|string|max:255',
            'device_type' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:255',
            'scanned_payload' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        try {
            $orderQrCode = DB::transaction(function () use ($request, $orderQrCode) {
                $orderQrCode = OrderQrCode::with([
                        'order.user',
                        'order.orderItems',
                        'order.pickupConfirmation',
                        'pickupConfirmation',
                    ])
                    ->where('id', $orderQrCode->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($orderQrCode->pickupConfirmation || $orderQrCode->order->pickupConfirmation) {
                    throw new \Exception('Pickup has already been confirmed for this order.');
                }

                if ($orderQrCode->isCancelled()) {
                    $this->createScanLog(
                        $request,
                        $orderQrCode,
                        QrScanLog::ACTION_COLLECT,
                        QrScanLog::STATUS_CANCELLED,
                        'Cancelled QR code cannot be used.',
                        'Cancelled QR code.'
                    );

                    throw new \Exception('Cancelled QR code cannot be used.');
                }

                if ($orderQrCode->isUsed()) {
                    $this->createScanLog(
                        $request,
                        $orderQrCode,
                        QrScanLog::ACTION_COLLECT,
                        QrScanLog::STATUS_USED,
                        'This QR code has already been used.',
                        'Duplicate collection attempt.'
                    );

                    throw new \Exception('This QR code has already been used.');
                }

                if ($orderQrCode->isExpired()) {
                    $orderQrCode->update([
                        'status' => OrderQrCode::STATUS_EXPIRED,
                        'updated_by' => $request->user()->id,
                    ]);

                    $this->createScanLog(
                        $request,
                        $orderQrCode,
                        QrScanLog::ACTION_COLLECT,
                        QrScanLog::STATUS_EXPIRED,
                        'This QR code has expired.',
                        'Expired QR code.'
                    );

                    throw new \Exception('This QR code has expired.');
                }

                if ($orderQrCode->order->payment_status !== Order::PAYMENT_PAID) {
                    $this->createScanLog(
                        $request,
                        $orderQrCode,
                        QrScanLog::ACTION_COLLECT,
                        QrScanLog::STATUS_UNPAID,
                        'Only paid orders can be collected.',
                        'Unpaid order.'
                    );

                    throw new \Exception('Only paid orders can be collected.');
                }

                if ($orderQrCode->order->order_status === Order::STATUS_CANCELLED) {
                    $this->createScanLog(
                        $request,
                        $orderQrCode,
                        QrScanLog::ACTION_COLLECT,
                        QrScanLog::STATUS_CANCELLED,
                        'Cancelled order cannot be collected.',
                        'Cancelled order.'
                    );

                    throw new \Exception('Cancelled order cannot be collected.');
                }

                if ($orderQrCode->order->order_status === Order::STATUS_COMPLETED) {
                    $this->createScanLog(
                        $request,
                        $orderQrCode,
                        QrScanLog::ACTION_COLLECT,
                        QrScanLog::STATUS_USED,
                        'This order has already been completed.',
                        'Completed order.'
                    );

                    throw new \Exception('This order has already been completed.');
                }

                $orderQrCode->update([
                    'status' => OrderQrCode::STATUS_USED,
                    'used_at' => now(),
                    'scanned_by' => $request->user()->id,
                    'scanned_at' => now(),
                    'updated_by' => $request->user()->id,
                ]);

                $scanLog = $this->createScanLog(
                    $request,
                    $orderQrCode,
                    QrScanLog::ACTION_COLLECT,
                    QrScanLog::STATUS_SUCCESS,
                    'QR code marked as used successfully.',
                    null
                );

                $orderQrCode->order->update([
                    'order_status' => Order::STATUS_COMPLETED,
                    'pickup_status' => Order::PICKUP_COLLECTED,
                    'completed_by' => $request->user()->id,
                    'completed_at' => now(),
                ]);

                $orderQrCode->order->orderItems()->update([
                    'item_status' => OrderItem::STATUS_COLLECTED,
                    'updated_by' => $request->user()->id,
                ]);

                PickupConfirmation::create([
                    'order_id' => $orderQrCode->order->id,
                    'order_qr_code_id' => $orderQrCode->id,
                    'qr_scan_log_id' => $scanLog->id,
                    'user_id' => $orderQrCode->user_id,

                    'confirmation_number' => $this->generatePickupConfirmationNumber(),
                    'confirmation_method' => PickupConfirmation::METHOD_QR_SCAN,
                    'status' => PickupConfirmation::STATUS_CONFIRMED,

                    'customer_name' => $orderQrCode->order->user?->name,
                    'customer_phone' => $orderQrCode->order->user?->phone,
                    'order_number' => $orderQrCode->order->order_number,
                    'qr_code_number' => $orderQrCode->qr_code_number,

                    'confirmed_by' => $request->user()->id,
                    'confirmed_at' => now(),

                    'device_name' => $request->device_name,
                    'device_type' => $request->device_type,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'location' => $request->location,

                    'notes' => $request->notes ?? 'Pickup confirmed by QR scan.',
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);

                return $orderQrCode;
            });

            $orderQrCode->load([
                'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
                'order.orderItems.foodItem:id,name,sku,price,unit',
                'user:id,name,email,phone,role,status',
                'scannedBy:id,name,email,phone,role',
                'scanLogs',
                'pickupConfirmation',
            ]);

            return $this->sendResponse($orderQrCode, 'QR code used and pickup confirmed successfully.');
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }
    }

    /**
     * Regenerate QR code for an order.
     */
    public function regenerate(Request $request, OrderQrCode $orderQrCode): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can regenerate order QR codes.');
        }

        if ($orderQrCode->isUsed()) {
            return $this->sendError('Used QR code cannot be regenerated.', [], 400);
        }

        $validator = Validator::make($request->all(), [
            'expires_at' => 'nullable|date|after:now',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $orderQrCode->load([
            'order',
            'pickupConfirmation',
        ]);

        if ($orderQrCode->pickupConfirmation) {
            return $this->sendError('QR code cannot be regenerated after pickup confirmation.', [], 400);
        }

        if ($orderQrCode->order->payment_status !== Order::PAYMENT_PAID) {
            return $this->sendError('QR code can only be regenerated for paid orders.', [], 400);
        }

        if ($orderQrCode->order->isCancelled()) {
            return $this->sendError('QR code cannot be regenerated for cancelled order.', [], 400);
        }

        if ($orderQrCode->order->isCompleted()) {
            return $this->sendError('QR code cannot be regenerated for completed order.', [], 400);
        }

        $qrToken = $this->generateQrToken();
        $qrCodeNumber = $this->generateQrCodeNumber();

        $orderQrCode->update([
            'qr_code_number' => $qrCodeNumber,
            'qr_token' => $qrToken,
            'qr_payload' => $this->buildQrPayload($orderQrCode->order, $qrCodeNumber, $qrToken),
            'status' => OrderQrCode::STATUS_ACTIVE,
            'expires_at' => $request->expires_at ?? now()->addDay(),
            'used_at' => null,
            'scanned_by' => null,
            'scanned_at' => null,
            'cancelled_by' => null,
            'cancelled_at' => null,
            'notes' => $request->notes ?? $orderQrCode->notes,
            'updated_by' => $request->user()->id,
        ]);

        $orderQrCode->load([
            'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
            'user:id,name,email,phone,role,status',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($orderQrCode, 'Order QR code regenerated successfully.');
    }

    /**
     * Cancel QR code.
     */
    public function cancel(Request $request, OrderQrCode $orderQrCode): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can cancel order QR codes.');
        }

        if ($orderQrCode->isUsed()) {
            return $this->sendError('Used QR code cannot be cancelled.', [], 400);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $orderQrCode->update([
            'status' => OrderQrCode::STATUS_CANCELLED,
            'cancelled_by' => $request->user()->id,
            'cancelled_at' => now(),
            'notes' => $request->notes,
            'updated_by' => $request->user()->id,
        ]);

        $orderQrCode->load([
            'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
            'user:id,name,email,phone,role,status',
            'cancelledBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($orderQrCode, 'Order QR code cancelled successfully.');
    }

    /**
     * Delete QR code.
     */
    public function destroy(Request $request, OrderQrCode $orderQrCode): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can delete order QR codes.');
        }

        $orderQrCode->delete();

        return $this->sendResponse([], 'Order QR code deleted successfully.');
    }

    /**
     * Restore deleted QR code.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can restore order QR codes.');
        }

        $qrCode = OrderQrCode::onlyTrashed()->find($id);

        if (!$qrCode) {
            return $this->sendNotFound('Deleted order QR code not found.');
        }

        $qrCode->restore();

        $qrCode->load([
            'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
            'user:id,name,email,phone,role,status',
        ]);

        return $this->sendResponse($qrCode, 'Order QR code restored successfully.');
    }

    /**
     * Find QR code using token or QR number.
     */
    private function findQrCode(Request $request): ?OrderQrCode
    {
        return OrderQrCode::query()
            ->where(function ($query) use ($request) {
                if ($request->filled('qr_token')) {
                    $query->where('qr_token', $request->qr_token);
                }

                if ($request->filled('qr_code_number')) {
                    $method = $request->filled('qr_token') ? 'orWhere' : 'where';
                    $query->{$method}('qr_code_number', $request->qr_code_number);
                }
            })
            ->first();
    }

    /**
     * Create scan log.
     */
    private function createScanLog(
        Request $request,
        ?OrderQrCode $qrCode,
        string $action,
        string $status,
        string $message,
        ?string $failureReason = null
    ): QrScanLog {
        return QrScanLog::create([
            'order_qr_code_id' => $qrCode?->id,
            'order_id' => $qrCode?->order_id,
            'user_id' => $qrCode?->user_id,
            'scanned_by' => $request->user()?->id,

            'scan_action' => $action,
            'scan_status' => $status,

            'qr_code_number' => $request->qr_code_number ?? $qrCode?->qr_code_number,
            'qr_token' => $request->qr_token ?? $qrCode?->qr_token,
            'scanned_payload' => $request->scanned_payload ?? $qrCode?->qr_payload,

            'message' => $message,
            'failure_reason' => $failureReason,

            'device_name' => $request->device_name,
            'device_type' => $request->device_type,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'location' => $request->location,

            'scanned_at' => now(),
        ]);
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
     * Generate unique pickup confirmation number.
     */
    private function generatePickupConfirmationNumber(): string
    {
        do {
            $number = 'PUC-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
        } while (PickupConfirmation::where('confirmation_number', $number)->exists());

        return $number;
    }

    /**
     * Build QR payload.
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