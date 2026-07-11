<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ActivityLogController extends BaseController
{
    /**
     * Display activity logs.
     */
    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();

        $query = ActivityLog::query()
            ->with([
                'user:id,name,email,phone,role,status',
                'createdBy:id,name,email,phone,role',
                'updatedBy:id,name,email,phone,role',
            ])
            ->orderByDesc('id');

        // Normal users can only view their own logs.
        if (!$this->canManageActivityLogs($authUser)) {
            $query->where('user_id', $authUser->id);
        }

        if ($request->filled('user_id') && $this->canManageActivityLogs($authUser)) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('module')) {
            $query->where('module', $request->module);
        }

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->subject_type);
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('occurred_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('occurred_at', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('log_number', 'like', '%' . $request->search . '%')
                    ->orWhere('module', 'like', '%' . $request->search . '%')
                    ->orWhere('action', 'like', '%' . $request->search . '%')
                    ->orWhere('description', 'like', '%' . $request->search . '%')
                    ->orWhere('ip_address', 'like', '%' . $request->search . '%')
                    ->orWhereHas('user', function ($userQuery) use ($request) {
                        $userQuery->where('name', 'like', '%' . $request->search . '%')
                            ->orWhere('email', 'like', '%' . $request->search . '%')
                            ->orWhere('phone', 'like', '%' . $request->search . '%');
                    });
            });
        }

        $perPage = $request->get('per_page', 20);

        $logs = $query->paginate($perPage);

        return $this->sendResponse($logs, 'Activity logs retrieved successfully.');
    }

    /**
     * Store manual activity log.
     *
     * Usually logs should be created automatically using ActivityLog::record().
     */
    public function store(Request $request): JsonResponse
    {
        if (!$this->canManageActivityLogs($request->user())) {
            return $this->sendForbidden('Only admin or staff can create manual activity logs.');
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|exists:users,id',
            'module' => 'required|string|max:100',
            'action' => 'required|string|max:100',
            'status' => 'nullable|in:success,failed,warning',
            'severity' => 'nullable|in:info,low,medium,high,critical',
            'description' => 'nullable|string|max:255',
            'subject_type' => 'nullable|string|max:255',
            'subject_id' => 'nullable|integer|min:1',
            'old_values' => 'nullable|array',
            'new_values' => 'nullable|array',
            'metadata' => 'nullable|array',
            'device_name' => 'nullable|string|max:255',
            'device_type' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $log = ActivityLog::record([
            'user_id' => $request->user_id ?? $request->user()->id,
            'module' => $request->module,
            'action' => $request->action,
            'status' => $request->status ?? ActivityLog::STATUS_SUCCESS,
            'severity' => $request->severity ?? ActivityLog::SEVERITY_INFO,
            'description' => $request->description,
            'subject_type' => $request->subject_type,
            'subject_id' => $request->subject_id,
            'old_values' => $request->old_values,
            'new_values' => $request->new_values,
            'metadata' => $request->metadata,
            'device_name' => $request->device_name,
            'device_type' => $request->device_type,
        ], $request);

        $log->load([
            'user:id,name,email,phone,role,status',
            'createdBy:id,name,email,phone,role',
        ]);

        return $this->sendCreated($log, 'Activity log created successfully.');
    }

    /**
     * Display one activity log.
     */
    public function show(Request $request, ActivityLog $activityLog): JsonResponse
    {
        $authUser = $request->user();

        if (!$this->canManageActivityLogs($authUser) && $activityLog->user_id !== $authUser->id) {
            return $this->sendForbidden('You can only view your own activity logs.');
        }

        $activityLog->load([
            'user:id,name,email,phone,role,status',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($activityLog, 'Activity log retrieved successfully.');
    }

    /**
     * Delete activity log.
     */
    public function destroy(Request $request, ActivityLog $activityLog): JsonResponse
    {
        if (!$this->canManageActivityLogs($request->user())) {
            return $this->sendForbidden('Only admin or staff can delete activity logs.');
        }

        $activityLog->delete();

        return $this->sendResponse([], 'Activity log deleted successfully.');
    }

    /**
     * Restore deleted activity log.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$this->canManageActivityLogs($request->user())) {
            return $this->sendForbidden('Only admin or staff can restore activity logs.');
        }

        $log = ActivityLog::onlyTrashed()->find($id);

        if (!$log) {
            return $this->sendNotFound('Deleted activity log not found.');
        }

        $log->restore();

        $log->load([
            'user:id,name,email,phone,role,status',
            'createdBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($log, 'Activity log restored successfully.');
    }

    /**
     * Activity log summary.
     */
    public function summary(Request $request): JsonResponse
    {
        if (!$this->canManageActivityLogs($request->user())) {
            return $this->sendForbidden('Only admin or staff can view activity log summary.');
        }

        $query = ActivityLog::query();

        if ($request->filled('from_date')) {
            $query->whereDate('occurred_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('occurred_at', '<=', $request->to_date);
        }

        if ($request->filled('module')) {
            $query->where('module', $request->module);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $totalLogs = (clone $query)->count();

        $successLogs = (clone $query)
            ->where('status', ActivityLog::STATUS_SUCCESS)
            ->count();

        $failedLogs = (clone $query)
            ->where('status', ActivityLog::STATUS_FAILED)
            ->count();

        $warningLogs = (clone $query)
            ->where('status', ActivityLog::STATUS_WARNING)
            ->count();

        $criticalLogs = (clone $query)
            ->where('severity', ActivityLog::SEVERITY_CRITICAL)
            ->count();

        $byModule = (clone $query)
            ->select('module', DB::raw('COUNT(*) as total_records'))
            ->groupBy('module')
            ->orderBy('module')
            ->get();

        $byAction = (clone $query)
            ->select('action', DB::raw('COUNT(*) as total_records'))
            ->groupBy('action')
            ->orderBy('action')
            ->get();

        $byStatus = (clone $query)
            ->select('status', DB::raw('COUNT(*) as total_records'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        $bySeverity = (clone $query)
            ->select('severity', DB::raw('COUNT(*) as total_records'))
            ->groupBy('severity')
            ->orderBy('severity')
            ->get();

        $latestLogs = (clone $query)
            ->with('user:id,name,email,phone,role,status')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return $this->sendResponse([
            'total_logs' => $totalLogs,
            'success_logs' => $successLogs,
            'failed_logs' => $failedLogs,
            'warning_logs' => $warningLogs,
            'critical_logs' => $criticalLogs,
            'by_module' => $byModule,
            'by_action' => $byAction,
            'by_status' => $byStatus,
            'by_severity' => $bySeverity,
            'latest_logs' => $latestLogs,
        ], 'Activity log summary retrieved successfully.');
    }

    /**
     * Check if user can manage/view all activity logs.
     */
    private function canManageActivityLogs($user): bool
    {
        if (!$user) {
            return false;
        }

        return method_exists($user, 'canManageInventory') && $user->canManageInventory();
    }
}