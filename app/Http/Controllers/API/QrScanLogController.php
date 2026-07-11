<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\QrScanLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QrScanLogController extends BaseController
{
    /**
     * Display QR scan logs.
     */
    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();

        $query = QrScanLog::query()
            ->with([
                'orderQrCode:id,order_id,user_id,qr_code_number,status,expires_at,used_at',
                'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
                'user:id,name,email,phone,role,status',
                'scannedBy:id,name,email,phone,role',
            ])
            ->orderByDesc('id');

        // Student can only see logs related to their own QR/order
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

        if ($request->filled('scanned_by') && $authUser->canManageOrders()) {
            $query->where('scanned_by', $request->scanned_by);
        }

        if ($request->filled('scan_action')) {
            $query->where('scan_action', $request->scan_action);
        }

        if ($request->filled('scan_status')) {
            $query->where('scan_status', $request->scan_status);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('scanned_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('scanned_at', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('qr_code_number', 'like', '%' . $request->search . '%')
                    ->orWhere('qr_token', 'like', '%' . $request->search . '%')
                    ->orWhere('message', 'like', '%' . $request->search . '%')
                    ->orWhere('failure_reason', 'like', '%' . $request->search . '%')
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

        $logs = $query->paginate($perPage);

        return $this->sendResponse($logs, 'QR scan logs retrieved successfully.');
    }

    /**
     * Display one QR scan log.
     */
    public function show(Request $request, QrScanLog $qrScanLog): JsonResponse
    {
        $authUser = $request->user();

        if (!$authUser->canManageOrders() && $qrScanLog->user_id !== $authUser->id) {
            return $this->sendForbidden('You can only view your own QR scan logs.');
        }

        $qrScanLog->load([
            'orderQrCode:id,order_id,user_id,qr_code_number,status,expires_at,used_at',
            'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
            'order.orderItems.foodItem:id,name,sku,price,unit',
            'user:id,name,email,phone,role,status',
            'scannedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($qrScanLog, 'QR scan log retrieved successfully.');
    }

    /**
     * Delete QR scan log.
     */
    public function destroy(Request $request, QrScanLog $qrScanLog): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can delete QR scan logs.');
        }

        $qrScanLog->delete();

        return $this->sendResponse([], 'QR scan log deleted successfully.');
    }

    /**
     * Restore deleted QR scan log.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can restore QR scan logs.');
        }

        $log = QrScanLog::onlyTrashed()->find($id);

        if (!$log) {
            return $this->sendNotFound('Deleted QR scan log not found.');
        }

        $log->restore();

        $log->load([
            'orderQrCode:id,order_id,user_id,qr_code_number,status',
            'order:id,user_id,order_number,total_amount,payment_status,order_status,pickup_status',
            'user:id,name,email,phone,role,status',
            'scannedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($log, 'QR scan log restored successfully.');
    }

    /**
     * QR scan log summary.
     */
    public function summary(Request $request): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can view QR scan summary.');
        }

        $query = QrScanLog::query();

        if ($request->filled('from_date')) {
            $query->whereDate('scanned_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('scanned_at', '<=', $request->to_date);
        }

        if ($request->filled('scanned_by')) {
            $query->where('scanned_by', $request->scanned_by);
        }

        $totalScans = (clone $query)->count();

        $successfulScans = (clone $query)
            ->whereIn('scan_status', [
                QrScanLog::STATUS_VALID,
                QrScanLog::STATUS_SUCCESS,
            ])
            ->count();

        $failedScans = $totalScans - $successfulScans;

        $byStatus = (clone $query)
            ->select('scan_status', DB::raw('COUNT(*) as total_records'))
            ->groupBy('scan_status')
            ->orderBy('scan_status')
            ->get();

        $byAction = (clone $query)
            ->select('scan_action', DB::raw('COUNT(*) as total_records'))
            ->groupBy('scan_action')
            ->orderBy('scan_action')
            ->get();

        return $this->sendResponse([
            'total_scans' => $totalScans,
            'successful_scans' => $successfulScans,
            'failed_scans' => $failedScans,
            'by_status' => $byStatus,
            'by_action' => $byAction,
        ], 'QR scan summary retrieved successfully.');
    }
}