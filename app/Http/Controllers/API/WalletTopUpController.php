<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\User;
use App\Models\WalletTopUp;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WalletTopUpController extends BaseController
{
    /**
     * Display wallet top-up requests.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = WalletTopUp::query()
            ->with([
                'user:id,name,email,phone,role,status,wallet_balance',
                'requestedBy:id,name,email,phone,role',
                'approvedBy:id,name,email,phone,role',
                'rejectedBy:id,name,email,phone,role',
                'cancelledBy:id,name,email,phone,role',
                'transaction',
            ])
            ->orderByDesc('id');

        // Student can only see their own top-up requests
        if (!$user->canManageWallet()) {
            $query->where('user_id', $user->id);
        }

        // Admin/staff can filter by user
        if ($request->filled('user_id') && $user->canManageWallet()) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('requested_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('requested_at', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('top_up_number', 'like', '%' . $request->search . '%')
                    ->orWhere('payment_reference', 'like', '%' . $request->search . '%')
                    ->orWhereHas('user', function ($userQuery) use ($request) {
                        $userQuery->where('name', 'like', '%' . $request->search . '%')
                            ->orWhere('email', 'like', '%' . $request->search . '%')
                            ->orWhere('phone', 'like', '%' . $request->search . '%');
                    });
            });
        }

        $perPage = $request->get('per_page', 20);

        $topUps = $query->paginate($perPage);

        return $this->sendResponse($topUps, 'Wallet top-up requests retrieved successfully.');
    }

    /**
     * Store a wallet top-up request.
     */
    public function store(Request $request): JsonResponse
    {
        $authUser = $request->user();

        $validator = Validator::make($request->all(), [
            // Admin/staff can create top-up for another user.
            // Student can create for self only.
            'user_id' => 'nullable|exists:users,id',

            'amount' => 'required|numeric|min:1',
            'payment_method' => 'nullable|in:cash,mobile_money,bank,card,manual',
            'payment_reference' => 'nullable|string|max:255',
            'payment_proof' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $targetUserId = $authUser->canManageWallet()
            ? ($request->user_id ?? $authUser->id)
            : $authUser->id;

        $topUp = WalletTopUp::create([
            'user_id' => $targetUserId,
            'top_up_number' => $this->generateTopUpNumber(),
            'amount' => $request->amount,
            'payment_method' => $request->payment_method ?? WalletTopUp::METHOD_CASH,
            'payment_reference' => $request->payment_reference,
            'payment_proof' => $request->payment_proof,
            'status' => WalletTopUp::STATUS_PENDING,
            'notes' => $request->notes,
            'admin_notes' => null,
            'requested_by' => $authUser->id,
            'requested_at' => now(),
        ]);

        $topUp->load([
            'user:id,name,email,phone,role,status,wallet_balance',
            'requestedBy:id,name,email,phone,role',
        ]);

        return $this->sendCreated($topUp, 'Wallet top-up request created successfully.');
    }

    /**
     * Display one wallet top-up request.
     */
    public function show(Request $request, WalletTopUp $walletTopUp): JsonResponse
    {
        $authUser = $request->user();

        if (!$authUser->canManageWallet() && $walletTopUp->user_id !== $authUser->id) {
            return $this->sendForbidden('You can only view your own wallet top-up requests.');
        }

        $walletTopUp->load([
            'user:id,name,email,phone,role,status,wallet_balance',
            'requestedBy:id,name,email,phone,role',
            'approvedBy:id,name,email,phone,role',
            'rejectedBy:id,name,email,phone,role',
            'cancelledBy:id,name,email,phone,role',
            'transaction',
        ]);

        return $this->sendResponse($walletTopUp, 'Wallet top-up request retrieved successfully.');
    }

    /**
     * Update a pending wallet top-up request.
     */
    public function update(Request $request, WalletTopUp $walletTopUp): JsonResponse
    {
        $authUser = $request->user();

        if (!$walletTopUp->isPending()) {
            return $this->sendError('Only pending top-up requests can be updated.', [], 400);
        }

        if (!$authUser->canManageWallet() && $walletTopUp->user_id !== $authUser->id) {
            return $this->sendForbidden('You can only update your own pending top-up requests.');
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|exists:users,id',
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'nullable|in:cash,mobile_money,bank,card,manual',
            'payment_reference' => 'nullable|string|max:255',
            'payment_proof' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $walletTopUp->update([
            'user_id' => $authUser->canManageWallet()
                ? ($request->user_id ?? $walletTopUp->user_id)
                : $walletTopUp->user_id,

            'amount' => $request->amount,
            'payment_method' => $request->payment_method ?? $walletTopUp->payment_method,
            'payment_reference' => $request->payment_reference,
            'payment_proof' => $request->payment_proof,
            'notes' => $request->notes,
        ]);

        $walletTopUp->load([
            'user:id,name,email,phone,role,status,wallet_balance',
            'requestedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($walletTopUp, 'Wallet top-up request updated successfully.');
    }

    /**
     * Delete a pending wallet top-up request.
     */
    public function destroy(Request $request, WalletTopUp $walletTopUp): JsonResponse
    {
        $authUser = $request->user();

        if (!$walletTopUp->isPending()) {
            return $this->sendError('Only pending top-up requests can be deleted.', [], 400);
        }

        if (!$authUser->canManageWallet() && $walletTopUp->user_id !== $authUser->id) {
            return $this->sendForbidden('You can only delete your own pending top-up requests.');
        }

        $walletTopUp->delete();

        return $this->sendResponse([], 'Wallet top-up request deleted successfully.');
    }

    /**
     * Approve wallet top-up, update user wallet balance,
     * and create wallet transaction history.
     */
    public function approve(Request $request, WalletTopUp $walletTopUp): JsonResponse
    {
        if (!$request->user()->canManageWallet()) {
            return $this->sendForbidden('Only admin or staff can approve wallet top-ups.');
        }

        if (!$walletTopUp->isPending()) {
            return $this->sendError('Only pending top-up requests can be approved.', [], 400);
        }

        $validator = Validator::make($request->all(), [
            'admin_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $walletTopUp = DB::transaction(function () use ($request, $walletTopUp) {
            $user = User::lockForUpdate()->findOrFail($walletTopUp->user_id);

            $balanceBefore = (float) $user->wallet_balance;
            $amount = (float) $walletTopUp->amount;
            $balanceAfter = $balanceBefore + $amount;

            // Update wallet balance
            $user->update([
                'wallet_balance' => $balanceAfter,
            ]);

            // Approve top-up
            $walletTopUp->update([
                'status' => WalletTopUp::STATUS_APPROVED,
                'admin_notes' => $request->admin_notes,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            // Record wallet transaction
            WalletTransaction::create([
                'user_id' => $user->id,
                'wallet_top_up_id' => $walletTopUp->id,
                'transaction_number' => $this->generateWalletTransactionNumber(),
                'transaction_type' => WalletTransaction::TYPE_CREDIT,
                'source_type' => WalletTransaction::SOURCE_TOP_UP,
                'source_id' => $walletTopUp->id,
                'reference_number' => $walletTopUp->top_up_number,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'status' => WalletTransaction::STATUS_COMPLETED,
                'description' => 'Wallet top-up approved',
                'notes' => $request->admin_notes,
                'processed_by' => $request->user()->id,
                'processed_at' => now(),
            ]);

            return $walletTopUp;
        });

        $walletTopUp->load([
            'user:id,name,email,phone,role,status,wallet_balance',
            'requestedBy:id,name,email,phone,role',
            'approvedBy:id,name,email,phone,role',
            'transaction',
        ]);

        return $this->sendResponse(
            $walletTopUp,
            'Wallet top-up approved, balance updated, and transaction recorded successfully.'
        );
    }

    /**
     * Reject wallet top-up request.
     */
    public function reject(Request $request, WalletTopUp $walletTopUp): JsonResponse
    {
        if (!$request->user()->canManageWallet()) {
            return $this->sendForbidden('Only admin or staff can reject wallet top-ups.');
        }

        if (!$walletTopUp->isPending()) {
            return $this->sendError('Only pending top-up requests can be rejected.', [], 400);
        }

        $validator = Validator::make($request->all(), [
            'admin_notes' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please provide rejection reason.');
        }

        $walletTopUp->update([
            'status' => WalletTopUp::STATUS_REJECTED,
            'admin_notes' => $request->admin_notes,
            'rejected_by' => $request->user()->id,
            'rejected_at' => now(),
        ]);

        $walletTopUp->load([
            'user:id,name,email,phone,role,status,wallet_balance',
            'requestedBy:id,name,email,phone,role',
            'rejectedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($walletTopUp, 'Wallet top-up request rejected successfully.');
    }

    /**
     * Cancel wallet top-up request.
     */
    public function cancel(Request $request, WalletTopUp $walletTopUp): JsonResponse
    {
        $authUser = $request->user();

        if (!$walletTopUp->isPending()) {
            return $this->sendError('Only pending top-up requests can be cancelled.', [], 400);
        }

        if (!$authUser->canManageWallet() && $walletTopUp->user_id !== $authUser->id) {
            return $this->sendForbidden('You can only cancel your own pending top-up requests.');
        }

        $validator = Validator::make($request->all(), [
            'admin_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $walletTopUp->update([
            'status' => WalletTopUp::STATUS_CANCELLED,
            'admin_notes' => $request->admin_notes,
            'cancelled_by' => $authUser->id,
            'cancelled_at' => now(),
        ]);

        $walletTopUp->load([
            'user:id,name,email,phone,role,status,wallet_balance',
            'requestedBy:id,name,email,phone,role',
            'cancelledBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($walletTopUp, 'Wallet top-up request cancelled successfully.');
    }

    /**
     * Restore deleted wallet top-up.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageWallet()) {
            return $this->sendForbidden('Only admin or staff can restore wallet top-ups.');
        }

        $topUp = WalletTopUp::onlyTrashed()->find($id);

        if (!$topUp) {
            return $this->sendNotFound('Deleted wallet top-up request not found.');
        }

        $topUp->restore();

        $topUp->load([
            'user:id,name,email,phone,role,status,wallet_balance',
            'requestedBy:id,name,email,phone,role',
            'transaction',
        ]);

        return $this->sendResponse($topUp, 'Wallet top-up request restored successfully.');
    }

    /**
     * Wallet top-up summary.
     */
    public function summary(Request $request): JsonResponse
    {
        if (!$request->user()->canManageWallet()) {
            return $this->sendForbidden('Only admin or staff can view wallet top-up summary.');
        }

        $query = WalletTopUp::query();

        if ($request->filled('from_date')) {
            $query->whereDate('requested_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('requested_at', '<=', $request->to_date);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $totalRequests = (clone $query)->count();

        $pendingAmount = (clone $query)
            ->where('status', WalletTopUp::STATUS_PENDING)
            ->sum('amount');

        $approvedAmount = (clone $query)
            ->where('status', WalletTopUp::STATUS_APPROVED)
            ->sum('amount');

        $rejectedAmount = (clone $query)
            ->where('status', WalletTopUp::STATUS_REJECTED)
            ->sum('amount');

        $cancelledAmount = (clone $query)
            ->where('status', WalletTopUp::STATUS_CANCELLED)
            ->sum('amount');

        $byStatus = (clone $query)
            ->select(
                'status',
                DB::raw('COUNT(*) as total_records'),
                DB::raw('SUM(amount) as total_amount')
            )
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        return $this->sendResponse([
            'total_requests' => $totalRequests,
            'pending_amount' => $pendingAmount,
            'approved_amount' => $approvedAmount,
            'rejected_amount' => $rejectedAmount,
            'cancelled_amount' => $cancelledAmount,
            'by_status' => $byStatus,
        ], 'Wallet top-up summary retrieved successfully.');
    }

    /**
     * Generate unique top-up number.
     */
    private function generateTopUpNumber(): string
    {
        do {
            $number = 'TOP-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (WalletTopUp::where('top_up_number', $number)->exists());

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
}