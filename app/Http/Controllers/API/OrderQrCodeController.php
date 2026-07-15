<?php

namespace App\Http\Controllers\API;

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
            ->with($this->defaultRelations())
            ->orderByDesc('id');

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
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                $q->where('qr_code_number', 'like', '%' . $search . '%')
                    ->orWhere('qr_token', 'like', '%' . $search . '%')
                    ->orWhereHas('order', function ($orderQuery) use ($search) {
                        $orderQuery->where(
                            'order_number',
                            'like',
                            '%' . $search . '%'
                        );
                    })
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery
                            ->where('name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%')
                            ->orWhere('phone', 'like', '%' . $search . '%');
                    });
            });
        }

        $perPage = min(max((int) $request->get('per_page', 20), 1), 200);
        $qrCodes = $query->paginate($perPage);

        return $this->sendResponse(
            $qrCodes,
            'Order QR codes retrieved successfully.'
        );
    }

    /**
     * Create a QR code for an eligible paid order.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden(
                'Only admin or staff can create order QR codes.'
            );
        }

        $validator = Validator::make($request->all(), [
            'order_id' => [
                'required',
                'integer',
                'exists:orders,id',
                'unique:order_qr_codes,order_id',
            ],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        try {
            $qrCode = DB::transaction(function () use ($request) {
                $order = Order::query()
                    ->whereKey($request->order_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($order->payment_status !== Order::PAYMENT_PAID) {
                    throw new \RuntimeException(
                        'QR code can only be created for paid orders.'
                    );
                }

                if ($order->isCancelled()) {
                    throw new \RuntimeException(
                        'QR code cannot be created for a cancelled order.'
                    );
                }

                if ($order->isCompleted()) {
                    throw new \RuntimeException(
                        'QR code cannot be created for a completed order.'
                    );
                }

                if (
                    OrderQrCode::withTrashed()
                        ->where('order_id', $order->id)
                        ->exists()
                ) {
                    throw new \RuntimeException(
                        'A QR code already exists for this order. Restore or regenerate it instead.'
                    );
                }

                $qrToken = $this->generateQrToken();
                $qrCodeNumber = $this->generateQrCodeNumber();

                return OrderQrCode::create([
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'qr_code_number' => $qrCodeNumber,
                    'qr_token' => $qrToken,
                    'qr_payload' => $this->buildQrPayload(
                        $order,
                        $qrCodeNumber,
                        $qrToken
                    ),
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
            });
        } catch (\Throwable $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }

        $qrCode->load($this->defaultRelations());

        return $this->sendCreated(
            $qrCode,
            'Order QR code created successfully.'
        );
    }

    /**
     * Display one order QR code.
     */
    public function show(
        Request $request,
        OrderQrCode $orderQrCode
    ): JsonResponse {
        $authUser = $request->user();

        if (
            !$authUser->canManageOrders() &&
            $orderQrCode->user_id !== $authUser->id
        ) {
            return $this->sendForbidden(
                'You can only view your own order QR codes.'
            );
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

        return $this->sendResponse(
            $orderQrCode,
            'Order QR code retrieved successfully.'
        );
    }

    /**
     * Update QR expiry or notes.
     *
     * Token and QR number remain immutable; use regenerate() for those fields.
     */
    public function update(
        Request $request,
        OrderQrCode $orderQrCode
    ): JsonResponse {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden(
                'Only admin or staff can update order QR codes.'
            );
        }

        if (
            $orderQrCode->isUsed() ||
            $orderQrCode->isCancelled()
        ) {
            return $this->sendError(
                'Used or cancelled QR codes cannot be updated.',
                [],
                400
            );
        }

        $validator = Validator::make($request->all(), [
            'expires_at' => ['sometimes', 'required', 'date', 'after:now'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        $data = ['updated_by' => $request->user()->id];

        if ($request->has('expires_at')) {
            $data['expires_at'] = $request->expires_at;
        }

        if ($request->has('notes')) {
            $data['notes'] = $request->notes;
        }

        $orderQrCode->update($data);
        $orderQrCode->load($this->defaultRelations());

        return $this->sendResponse(
            $orderQrCode,
            'Order QR code updated successfully.'
        );
    }

    /**
     * Verify a QR code by token and/or QR number.
     */
    public function verify(Request $request): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden(
                'Only admin or staff can verify order QR codes.'
            );
        }

        $validator = Validator::make($request->all(), [
            'qr_token' => ['nullable', 'string'],
            'qr_code_number' => ['nullable', 'string'],
            'scanned_payload' => ['nullable', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_type' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        if (
            !$request->filled('qr_token') &&
            !$request->filled('qr_code_number')
        ) {
            $this->createScanLog(
                $request,
                null,
                QrScanLog::ACTION_VERIFY,
                QrScanLog::STATUS_FAILED,
                'QR token or QR code number is required.',
                'Missing QR input.'
            );

            return $this->sendError(
                'QR token or QR code number is required.',
                [],
                422
            );
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

        $failure = $this->validateQrForPickup($qrCode);

        if ($failure !== null) {
            if ($failure['status'] === QrScanLog::STATUS_EXPIRED) {
                $qrCode->update([
                    'status' => OrderQrCode::STATUS_EXPIRED,
                    'updated_by' => $request->user()->id,
                ]);
            }

            $this->createScanLog(
                $request,
                $qrCode,
                QrScanLog::ACTION_VERIFY,
                $failure['status'],
                $failure['message'],
                $failure['reason']
            );

            return $this->sendError(
                $failure['message'],
                [],
                $failure['http_status']
            );
        }

        $this->createScanLog(
            $request,
            $qrCode,
            QrScanLog::ACTION_VERIFY,
            QrScanLog::STATUS_VALID,
            'QR code verified successfully.',
            null
        );

        return $this->sendResponse(
            $qrCode,
            'QR code verified successfully.'
        );
    }

    /**
     * Mark QR code as used and create the official pickup confirmation.
     *
     * Business failures are returned from the transaction instead of thrown,
     * allowing the failure scan log to be committed outside the transaction.
     */
    public function markUsed(
        Request $request,
        OrderQrCode $orderQrCode
    ): JsonResponse {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden(
                'Only admin or staff can mark QR code as used.'
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
            $result = DB::transaction(function () use (
                $request,
                $orderQrCode
            ) {
                $qrCode = OrderQrCode::with([
                        'order.user',
                        'order.orderItems',
                        'pickupConfirmation',
                    ])
                    ->whereKey($orderQrCode->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $failure = $this->validateQrForPickup($qrCode);

                if ($failure !== null) {
                    if ($failure['status'] === QrScanLog::STATUS_EXPIRED) {
                        $qrCode->update([
                            'status' => OrderQrCode::STATUS_EXPIRED,
                            'updated_by' => $request->user()->id,
                        ]);
                    }

                    return [
                        'success' => false,
                        'qr_code_id' => $qrCode->id,
                        'failure' => $failure,
                    ];
                }

                $qrCode->update([
                    'status' => OrderQrCode::STATUS_USED,
                    'used_at' => now(),
                    'scanned_by' => $request->user()->id,
                    'scanned_at' => now(),
                    'updated_by' => $request->user()->id,
                ]);

                $scanLog = $this->createScanLog(
                    $request,
                    $qrCode,
                    QrScanLog::ACTION_COLLECT,
                    QrScanLog::STATUS_SUCCESS,
                    'QR code marked as used successfully.',
                    null
                );

                $qrCode->order->update([
                    'order_status' => Order::STATUS_COMPLETED,
                    'pickup_status' => Order::PICKUP_COLLECTED,
                    'completed_by' => $request->user()->id,
                    'completed_at' => now(),
                ]);

                $qrCode->order->orderItems()->update([
                    'item_status' => OrderItem::STATUS_COLLECTED,
                    'updated_by' => $request->user()->id,
                ]);

                PickupConfirmation::create([
                    'order_id' => $qrCode->order->id,
                    'order_qr_code_id' => $qrCode->id,
                    'qr_scan_log_id' => $scanLog->id,
                    'user_id' => $qrCode->user_id,
                    'confirmation_number' => $this->generatePickupConfirmationNumber(),
                    'confirmation_method' => PickupConfirmation::METHOD_QR_SCAN,
                    'status' => PickupConfirmation::STATUS_CONFIRMED,
                    'customer_name' => $qrCode->order->user?->name,
                    'customer_phone' => $qrCode->order->user?->phone,
                    'order_number' => $qrCode->order->order_number,
                    'qr_code_number' => $qrCode->qr_code_number,
                    'confirmed_by' => $request->user()->id,
                    'confirmed_at' => now(),
                    'device_name' => $request->device_name,
                    'device_type' => $request->device_type,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'location' => $request->location,
                    'notes' => $request->notes
                        ?? 'Pickup confirmed by QR scan.',
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);

                return [
                    'success' => true,
                    'qr_code_id' => $qrCode->id,
                ];
            });
        } catch (\Throwable $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }

        if (!$result['success']) {
            $failedQrCode = OrderQrCode::find($result['qr_code_id']);
            $failure = $result['failure'];

            $this->createScanLog(
                $request,
                $failedQrCode,
                QrScanLog::ACTION_COLLECT,
                $failure['status'],
                $failure['message'],
                $failure['reason']
            );

            return $this->sendError(
                $failure['message'],
                [],
                $failure['http_status']
            );
        }

        $usedQrCode = OrderQrCode::findOrFail($result['qr_code_id']);
        $usedQrCode->load([
            'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
            'order.orderItems.foodItem:id,name,sku,price,unit',
            'user:id,name,email,phone,role,status',
            'scannedBy:id,name,email,phone,role',
            'scanLogs',
            'pickupConfirmation',
        ]);

        return $this->sendResponse(
            $usedQrCode,
            'QR code used and pickup confirmed successfully.'
        );
    }

    /**
     * Regenerate QR code credentials.
     */
    public function regenerate(
        Request $request,
        OrderQrCode $orderQrCode
    ): JsonResponse {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden(
                'Only admin or staff can regenerate order QR codes.'
            );
        }

        $validator = Validator::make($request->all(), [
            'expires_at' => ['nullable', 'date', 'after:now'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        try {
            $orderQrCode = DB::transaction(function () use (
                $request,
                $orderQrCode
            ) {
                $qrCode = OrderQrCode::with([
                        'order',
                        'pickupConfirmation',
                    ])
                    ->whereKey($orderQrCode->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($qrCode->isUsed()) {
                    throw new \RuntimeException(
                        'Used QR code cannot be regenerated.'
                    );
                }

                if ($qrCode->pickupConfirmation) {
                    throw new \RuntimeException(
                        'QR code cannot be regenerated after pickup confirmation.'
                    );
                }

                if ($qrCode->order->payment_status !== Order::PAYMENT_PAID) {
                    throw new \RuntimeException(
                        'QR code can only be regenerated for paid orders.'
                    );
                }

                if ($qrCode->order->isCancelled()) {
                    throw new \RuntimeException(
                        'QR code cannot be regenerated for a cancelled order.'
                    );
                }

                if ($qrCode->order->isCompleted()) {
                    throw new \RuntimeException(
                        'QR code cannot be regenerated for a completed order.'
                    );
                }

                $qrToken = $this->generateQrToken();
                $qrCodeNumber = $this->generateQrCodeNumber();

                $qrCode->update([
                    'qr_code_number' => $qrCodeNumber,
                    'qr_token' => $qrToken,
                    'qr_payload' => $this->buildQrPayload(
                        $qrCode->order,
                        $qrCodeNumber,
                        $qrToken
                    ),
                    'status' => OrderQrCode::STATUS_ACTIVE,
                    'expires_at' => $request->expires_at ?? now()->addDay(),
                    'used_at' => null,
                    'scanned_by' => null,
                    'scanned_at' => null,
                    'cancelled_by' => null,
                    'cancelled_at' => null,
                    'notes' => $request->notes ?? $qrCode->notes,
                    'updated_by' => $request->user()->id,
                ]);

                return $qrCode;
            });
        } catch (\Throwable $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }

        $orderQrCode->load($this->defaultRelations());

        return $this->sendResponse(
            $orderQrCode,
            'Order QR code regenerated successfully.'
        );
    }

    /**
     * Cancel a QR code that has not been used.
     */
    public function cancel(
        Request $request,
        OrderQrCode $orderQrCode
    ): JsonResponse {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden(
                'Only admin or staff can cancel order QR codes.'
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

        $orderQrCode->load(['order', 'pickupConfirmation']);

        if ($orderQrCode->isUsed() || $orderQrCode->pickupConfirmation) {
            return $this->sendError(
                'A used or confirmed pickup QR code cannot be cancelled.',
                [],
                400
            );
        }

        if ($orderQrCode->order->isCompleted()) {
            return $this->sendError(
                'QR code cannot be cancelled after order completion.',
                [],
                400
            );
        }

        $orderQrCode->update([
            'status' => OrderQrCode::STATUS_CANCELLED,
            'cancelled_by' => $request->user()->id,
            'cancelled_at' => now(),
            'notes' => $request->notes,
            'updated_by' => $request->user()->id,
        ]);

        $orderQrCode->load($this->defaultRelations());

        return $this->sendResponse(
            $orderQrCode,
            'Order QR code cancelled successfully.'
        );
    }

    /**
     * Delete only a cancelled or expired unused QR history record.
     */
    public function destroy(
        Request $request,
        OrderQrCode $orderQrCode
    ): JsonResponse {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden(
                'Only admin or staff can delete order QR codes.'
            );
        }

        $orderQrCode->load('pickupConfirmation');

        if ($orderQrCode->pickupConfirmation || $orderQrCode->isUsed()) {
            return $this->sendError(
                'Used or pickup-confirmed QR records cannot be deleted.',
                [],
                400
            );
        }

        if (
            !$orderQrCode->isCancelled() &&
            $orderQrCode->status !== OrderQrCode::STATUS_EXPIRED
        ) {
            return $this->sendError(
                'Cancel or expire the QR code before deleting it.',
                [],
                400
            );
        }

        $orderQrCode->delete();

        return $this->sendResponse(
            [],
            'Order QR code deleted successfully.'
        );
    }

    /**
     * Restore a deleted QR code history record.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden(
                'Only admin or staff can restore order QR codes.'
            );
        }

        $qrCode = OrderQrCode::onlyTrashed()->find($id);

        if (!$qrCode) {
            return $this->sendNotFound(
                'Deleted order QR code not found.'
            );
        }

        $qrCode->restore();
        $qrCode->load($this->defaultRelations());

        return $this->sendResponse(
            $qrCode,
            'Order QR code restored successfully.'
        );
    }

    /**
     * Validate the current QR and order pickup state.
     */
    private function validateQrForPickup(
        OrderQrCode $qrCode
    ): ?array {
        if (
            $qrCode->pickupConfirmation ||
            PickupConfirmation::where('order_id', $qrCode->order_id)->exists()
        ) {
            return [
                'status' => QrScanLog::STATUS_USED,
                'message' => 'Pickup has already been confirmed for this QR code.',
                'reason' => 'Pickup already confirmed.',
                'http_status' => 400,
            ];
        }

        if ($qrCode->isCancelled()) {
            return [
                'status' => QrScanLog::STATUS_CANCELLED,
                'message' => 'This QR code has been cancelled.',
                'reason' => 'Cancelled QR code.',
                'http_status' => 400,
            ];
        }

        if ($qrCode->isUsed()) {
            return [
                'status' => QrScanLog::STATUS_USED,
                'message' => 'This QR code has already been used.',
                'reason' => 'Duplicate QR scan.',
                'http_status' => 400,
            ];
        }

        if ($qrCode->isExpired()) {
            return [
                'status' => QrScanLog::STATUS_EXPIRED,
                'message' => 'This QR code has expired.',
                'reason' => 'Expired QR code.',
                'http_status' => 400,
            ];
        }

        if (!$qrCode->order) {
            return [
                'status' => QrScanLog::STATUS_INVALID,
                'message' => 'The order linked to this QR code was not found.',
                'reason' => 'Missing order.',
                'http_status' => 404,
            ];
        }

        if ($qrCode->order->payment_status !== Order::PAYMENT_PAID) {
            return [
                'status' => QrScanLog::STATUS_UNPAID,
                'message' => 'This order is not paid.',
                'reason' => 'Unpaid order.',
                'http_status' => 400,
            ];
        }

        if ($qrCode->order->order_status === Order::STATUS_CANCELLED) {
            return [
                'status' => QrScanLog::STATUS_CANCELLED,
                'message' => 'This order has been cancelled.',
                'reason' => 'Cancelled order.',
                'http_status' => 400,
            ];
        }

        if ($qrCode->order->order_status === Order::STATUS_COMPLETED) {
            return [
                'status' => QrScanLog::STATUS_USED,
                'message' => 'This order has already been completed.',
                'reason' => 'Completed order.',
                'http_status' => 400,
            ];
        }

        if (
            $qrCode->order->order_status !== Order::STATUS_READY ||
            $qrCode->order->pickup_status !== Order::PICKUP_READY
        ) {
            return [
                'status' => QrScanLog::STATUS_FAILED,
                'message' => 'This order is not ready for pickup.',
                'reason' => 'Order not ready.',
                'http_status' => 400,
            ];
        }

        return null;
    }

    /**
     * Find QR code. When both identifiers are supplied, both must match.
     */
    private function findQrCode(Request $request): ?OrderQrCode
    {
        $query = OrderQrCode::query();

        if ($request->filled('qr_token')) {
            $query->where('qr_token', $request->qr_token);
        }

        if ($request->filled('qr_code_number')) {
            $query->where(
                'qr_code_number',
                $request->qr_code_number
            );
        }

        return $query->first();
    }

    /**
     * Create an immutable QR scan log.
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
            'qr_code_number' => $request->qr_code_number
                ?? $qrCode?->qr_code_number,
            'qr_token' => $request->qr_token ?? $qrCode?->qr_token,
            'scanned_payload' => $request->scanned_payload
                ?? $qrCode?->qr_payload,
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

    private function defaultRelations(): array
    {
        return [
            'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
            'user:id,name,email,phone,role,status',
            'scanLogs:id,order_qr_code_id,scan_action,scan_status,scanned_by,scanned_at,message',
            'pickupConfirmation:id,order_id,order_qr_code_id,confirmation_number,status,confirmed_by,confirmed_at',
            'scannedBy:id,name,email,phone,role',
            'cancelledBy:id,name,email,phone,role',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ];
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