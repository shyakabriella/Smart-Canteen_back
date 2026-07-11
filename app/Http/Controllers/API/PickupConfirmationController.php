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

class PickupConfirmationController extends BaseController
{
    /**
     * Display pickup confirmations.
     */
    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();

        $query = PickupConfirmation::query()
            ->with([
                'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
                'order.orderItems.foodItem:id,name,sku,price,unit',
                'orderQrCode:id,order_id,user_id,qr_code_number,status,expires_at,used_at',
                'qrScanLog:id,order_qr_code_id,order_id,scan_action,scan_status,scanned_by,scanned_at,message',
                'user:id,name,email,phone,role,status',
                'confirmedBy:id,name,email,phone,role',
                'cancelledBy:id,name,email,phone,role',
                'createdBy:id,name,email,phone,role',
                'updatedBy:id,name,email,phone,role',
            ])
            ->orderByDesc('id');

        // Students can only see their own pickup confirmations
        if (!$authUser->canManageOrders()) {
            $query->where('user_id', $authUser->id);
        }

        if ($request->filled('user_id') && $authUser->canManageOrders()) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->order_id);
        }

        if ($request->filled('order_qr_code_id')) {
            $query->where('order_qr_code_id', $request->order_qr_code_id);
        }

        if ($request->filled('confirmed_by') && $authUser->canManageOrders()) {
            $query->where('confirmed_by', $request->confirmed_by);
        }

        if ($request->filled('confirmation_method')) {
            $query->where('confirmation_method', $request->confirmation_method);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('confirmed_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('confirmed_at', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('confirmation_number', 'like', '%' . $request->search . '%')
                    ->orWhere('order_number', 'like', '%' . $request->search . '%')
                    ->orWhere('qr_code_number', 'like', '%' . $request->search . '%')
                    ->orWhere('customer_name', 'like', '%' . $request->search . '%')
                    ->orWhere('customer_phone', 'like', '%' . $request->search . '%')
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

        $confirmations = $query->paginate($perPage);

        return $this->sendResponse($confirmations, 'Pickup confirmations retrieved successfully.');
    }

    /**
     * Store manual or QR-based pickup confirmation.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can confirm pickup.');
        }

        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,id|unique:pickup_confirmations,order_id',
            'order_qr_code_id' => 'nullable|exists:order_qr_codes,id',
            'qr_scan_log_id' => 'nullable|exists:qr_scan_logs,id',
            'confirmation_method' => 'nullable|in:qr_scan,manual',
            'device_name' => 'nullable|string|max:255',
            'device_type' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        try {
            $confirmation = DB::transaction(function () use ($request) {
                $order = Order::with([
                        'user',
                        'orderItems',
                        'qrCode',
                        'pickupConfirmation',
                    ])
                    ->where('id', $request->order_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($order->pickupConfirmation) {
                    throw new \Exception('Pickup has already been confirmed for this order.');
                }

                if ($order->payment_status !== Order::PAYMENT_PAID) {
                    throw new \Exception('Only paid orders can be confirmed for pickup.');
                }

                if ($order->isCancelled()) {
                    throw new \Exception('Cancelled order cannot be confirmed for pickup.');
                }

                $qrCode = null;

                if ($request->filled('order_qr_code_id')) {
                    $qrCode = OrderQrCode::where('id', $request->order_qr_code_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($qrCode->order_id !== $order->id) {
                        throw new \Exception('This QR code does not belong to the selected order.');
                    }
                } elseif ($order->qrCode) {
                    $qrCode = OrderQrCode::where('id', $order->qrCode->id)
                        ->lockForUpdate()
                        ->first();
                }

                if ($qrCode) {
                    if ($qrCode->isCancelled()) {
                        throw new \Exception('Cancelled QR code cannot be used for pickup confirmation.');
                    }

                    if ($qrCode->isExpired()) {
                        $qrCode->update([
                            'status' => OrderQrCode::STATUS_EXPIRED,
                            'updated_by' => $request->user()->id,
                        ]);

                        throw new \Exception('Expired QR code cannot be used for pickup confirmation.');
                    }

                    if (!$qrCode->isUsed()) {
                        $qrCode->update([
                            'status' => OrderQrCode::STATUS_USED,
                            'used_at' => now(),
                            'scanned_by' => $request->user()->id,
                            'scanned_at' => now(),
                            'updated_by' => $request->user()->id,
                        ]);
                    }
                }

                $qrScanLog = null;

                if ($request->filled('qr_scan_log_id')) {
                    $qrScanLog = QrScanLog::find($request->qr_scan_log_id);
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

                return PickupConfirmation::create([
                    'order_id' => $order->id,
                    'order_qr_code_id' => $qrCode?->id,
                    'qr_scan_log_id' => $qrScanLog?->id,
                    'user_id' => $order->user_id,

                    'confirmation_number' => $this->generateConfirmationNumber(),
                    'confirmation_method' => $request->confirmation_method
                        ?? ($qrCode ? PickupConfirmation::METHOD_QR_SCAN : PickupConfirmation::METHOD_MANUAL),
                    'status' => PickupConfirmation::STATUS_CONFIRMED,

                    'customer_name' => $order->user?->name,
                    'customer_phone' => $order->user?->phone,
                    'order_number' => $order->order_number,
                    'qr_code_number' => $qrCode?->qr_code_number,

                    'confirmed_by' => $request->user()->id,
                    'confirmed_at' => now(),

                    'device_name' => $request->device_name,
                    'device_type' => $request->device_type,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'location' => $request->location,

                    'notes' => $request->notes,
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);
            });

            $confirmation->load([
                'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
                'order.orderItems.foodItem:id,name,sku,price,unit',
                'orderQrCode:id,order_id,user_id,qr_code_number,status,expires_at,used_at',
                'qrScanLog:id,order_qr_code_id,order_id,scan_action,scan_status,scanned_by,scanned_at,message',
                'user:id,name,email,phone,role,status',
                'confirmedBy:id,name,email,phone,role',
            ]);

            return $this->sendCreated($confirmation, 'Pickup confirmed successfully.');
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }
    }

    /**
     * Display one pickup confirmation.
     */
    public function show(Request $request, PickupConfirmation $pickupConfirmation): JsonResponse
    {
        $authUser = $request->user();

        if (!$authUser->canManageOrders() && $pickupConfirmation->user_id !== $authUser->id) {
            return $this->sendForbidden('You can only view your own pickup confirmation.');
        }

        $pickupConfirmation->load([
            'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
            'order.orderItems.foodItem:id,name,sku,price,unit',
            'orderQrCode:id,order_id,user_id,qr_code_number,status,expires_at,used_at',
            'qrScanLog:id,order_qr_code_id,order_id,scan_action,scan_status,scanned_by,scanned_at,message',
            'user:id,name,email,phone,role,status',
            'confirmedBy:id,name,email,phone,role',
            'cancelledBy:id,name,email,phone,role',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($pickupConfirmation, 'Pickup confirmation retrieved successfully.');
    }

    /**
     * Cancel pickup confirmation record.
     *
     * This does not refund wallet or return stock.
     * Use order cancel endpoint when you need financial/stock reversal.
     */
    public function cancel(Request $request, PickupConfirmation $pickupConfirmation): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can cancel pickup confirmations.');
        }

        if ($pickupConfirmation->isCancelled()) {
            return $this->sendError('Pickup confirmation is already cancelled.', [], 400);
        }

        $validator = Validator::make($request->all(), [
            'cancellation_reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please provide cancellation reason.');
        }

        $pickupConfirmation->update([
            'status' => PickupConfirmation::STATUS_CANCELLED,
            'cancelled_by' => $request->user()->id,
            'cancelled_at' => now(),
            'cancellation_reason' => $request->cancellation_reason,
            'updated_by' => $request->user()->id,
        ]);

        $pickupConfirmation->load([
            'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
            'user:id,name,email,phone,role,status',
            'confirmedBy:id,name,email,phone,role',
            'cancelledBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($pickupConfirmation, 'Pickup confirmation cancelled successfully.');
    }

    /**
     * Delete pickup confirmation.
     */
    public function destroy(Request $request, PickupConfirmation $pickupConfirmation): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can delete pickup confirmations.');
        }

        $pickupConfirmation->delete();

        return $this->sendResponse([], 'Pickup confirmation deleted successfully.');
    }

    /**
     * Restore deleted pickup confirmation.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can restore pickup confirmations.');
        }

        $confirmation = PickupConfirmation::onlyTrashed()->find($id);

        if (!$confirmation) {
            return $this->sendNotFound('Deleted pickup confirmation not found.');
        }

        $confirmation->restore();

        $confirmation->load([
            'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
            'user:id,name,email,phone,role,status',
            'confirmedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($confirmation, 'Pickup confirmation restored successfully.');
    }

    /**
     * Pickup confirmation summary.
     */
    public function summary(Request $request): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can view pickup confirmation summary.');
        }

        $query = PickupConfirmation::query();

        if ($request->filled('from_date')) {
            $query->whereDate('confirmed_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('confirmed_at', '<=', $request->to_date);
        }

        if ($request->filled('confirmed_by')) {
            $query->where('confirmed_by', $request->confirmed_by);
        }

        if ($request->filled('confirmation_method')) {
            $query->where('confirmation_method', $request->confirmation_method);
        }

        $totalConfirmations = (clone $query)->count();

        $confirmedCount = (clone $query)
            ->where('status', PickupConfirmation::STATUS_CONFIRMED)
            ->count();

        $cancelledCount = (clone $query)
            ->where('status', PickupConfirmation::STATUS_CANCELLED)
            ->count();

        $byMethod = (clone $query)
            ->select('confirmation_method', DB::raw('COUNT(*) as total_records'))
            ->groupBy('confirmation_method')
            ->orderBy('confirmation_method')
            ->get();

        $byStatus = (clone $query)
            ->select('status', DB::raw('COUNT(*) as total_records'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        return $this->sendResponse([
            'total_confirmations' => $totalConfirmations,
            'confirmed_count' => $confirmedCount,
            'cancelled_count' => $cancelledCount,
            'by_method' => $byMethod,
            'by_status' => $byStatus,
        ], 'Pickup confirmation summary retrieved successfully.');
    }

    /**
     * Generate unique pickup confirmation number.
     */
    private function generateConfirmationNumber(): string
    {
        do {
            $number = 'PUC-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
        } while (PickupConfirmation::where('confirmation_number', $number)->exists());

        return $number;
    }
}