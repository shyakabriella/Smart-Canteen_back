<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WalletTransactionController extends BaseController
{
    /**
     * Display wallet transactions.
     */
    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();

        $query = WalletTransaction::query()
            ->with([
                'user:id,name,email,phone,role,status,wallet_balance',
                'walletTopUp:id,top_up_number,amount,payment_method,status',
                'processedBy:id,name,email,phone,role',
            ])
            ->orderByDesc('id');

        // Student can only see own wallet transactions
        if (!$authUser->canManageWallet()) {
            $query->where('user_id', $authUser->id);
        }

        if ($request->filled('user_id') && $authUser->canManageWallet()) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('transaction_type')) {
            $query->where('transaction_type', $request->transaction_type);
        }

        if ($request->filled('source_type')) {
            $query->where('source_type', $request->source_type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('processed_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('processed_at', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('transaction_number', 'like', '%' . $request->search . '%')
                    ->orWhere('reference_number', 'like', '%' . $request->search . '%')
                    ->orWhere('description', 'like', '%' . $request->search . '%')
                    ->orWhereHas('user', function ($userQuery) use ($request) {
                        $userQuery->where('name', 'like', '%' . $request->search . '%')
                            ->orWhere('email', 'like', '%' . $request->search . '%')
                            ->orWhere('phone', 'like', '%' . $request->search . '%');
                    });
            });
        }

        $perPage = $request->get('per_page', 20);

        $transactions = $query->paginate($perPage);

        return $this->sendResponse($transactions, 'Wallet transactions retrieved successfully.');
    }

    /**
     * Store manual wallet transaction.
     *
     * Admin/staff only.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->canManageWallet()) {
            return $this->sendForbidden('Only admin or staff can create wallet transactions.');
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'transaction_type' => 'required|in:credit,debit',
            'amount' => 'required|numeric|min:1',
            'reference_number' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        try {
            $transaction = DB::transaction(function () use ($request) {
                $user = User::lockForUpdate()->findOrFail($request->user_id);

                $balanceBefore = (float) $user->wallet_balance;
                $amount = (float) $request->amount;

                if ($request->transaction_type === WalletTransaction::TYPE_DEBIT && $amount > $balanceBefore) {
                    throw new \Exception('Not enough wallet balance.');
                }

                $balanceAfter = $request->transaction_type === WalletTransaction::TYPE_CREDIT
                    ? $balanceBefore + $amount
                    : $balanceBefore - $amount;

                $user->update([
                    'wallet_balance' => $balanceAfter,
                ]);

                return WalletTransaction::create([
                    'user_id' => $user->id,
                    'wallet_top_up_id' => null,
                    'transaction_number' => $this->generateTransactionNumber(),
                    'transaction_type' => $request->transaction_type,
                    'source_type' => WalletTransaction::SOURCE_MANUAL_ADJUSTMENT,
                    'source_id' => null,
                    'reference_number' => $request->reference_number,
                    'amount' => $amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'status' => WalletTransaction::STATUS_COMPLETED,
                    'description' => $request->description ?? 'Manual wallet adjustment',
                    'notes' => $request->notes,
                    'processed_by' => $request->user()->id,
                    'processed_at' => now(),
                ]);
            });

            $transaction->load([
                'user:id,name,email,phone,role,status,wallet_balance',
                'processedBy:id,name,email,phone,role',
            ]);

            return $this->sendCreated($transaction, 'Wallet transaction created successfully.');
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }
    }

    /**
     * Display one wallet transaction.
     */
    public function show(Request $request, WalletTransaction $walletTransaction): JsonResponse
    {
        $authUser = $request->user();

        if (!$authUser->canManageWallet() && $walletTransaction->user_id !== $authUser->id) {
            return $this->sendForbidden('You can only view your own wallet transactions.');
        }

        $walletTransaction->load([
            'user:id,name,email,phone,role,status,wallet_balance',
            'walletTopUp:id,top_up_number,amount,payment_method,status',
            'processedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($walletTransaction, 'Wallet transaction retrieved successfully.');
    }

    /**
     * Update transaction notes only.
     *
     * Important:
     * Wallet amount cannot be updated here because this is a financial history record.
     */
    public function update(Request $request, WalletTransaction $walletTransaction): JsonResponse
    {
        if (!$request->user()->canManageWallet()) {
            return $this->sendForbidden('Only admin or staff can update wallet transaction notes.');
        }

        $validator = Validator::make($request->all(), [
            'description' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $walletTransaction->update([
            'description' => $request->description ?? $walletTransaction->description,
            'notes' => $request->notes ?? $walletTransaction->notes,
        ]);

        $walletTransaction->load([
            'user:id,name,email,phone,role,status,wallet_balance',
            'walletTopUp:id,top_up_number,amount,payment_method,status',
            'processedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($walletTransaction, 'Wallet transaction updated successfully.');
    }

    /**
     * Soft delete wallet transaction.
     *
     * This does not reverse wallet balance.
     */
    public function destroy(Request $request, WalletTransaction $walletTransaction): JsonResponse
    {
        if (!$request->user()->canManageWallet()) {
            return $this->sendForbidden('Only admin or staff can delete wallet transaction history.');
        }

        $walletTransaction->delete();

        return $this->sendResponse([], 'Wallet transaction deleted successfully.');
    }

    /**
     * Restore deleted wallet transaction.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageWallet()) {
            return $this->sendForbidden('Only admin or staff can restore wallet transactions.');
        }

        $transaction = WalletTransaction::onlyTrashed()->find($id);

        if (!$transaction) {
            return $this->sendNotFound('Deleted wallet transaction not found.');
        }

        $transaction->restore();

        $transaction->load([
            'user:id,name,email,phone,role,status,wallet_balance',
            'processedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($transaction, 'Wallet transaction restored successfully.');
    }

    /**
     * Wallet transaction summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $authUser = $request->user();

        $query = WalletTransaction::query();

        if (!$authUser->canManageWallet()) {
            $query->where('user_id', $authUser->id);
        }

        if ($request->filled('user_id') && $authUser->canManageWallet()) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('processed_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('processed_at', '<=', $request->to_date);
        }

        $totalCredit = (clone $query)
            ->where('transaction_type', WalletTransaction::TYPE_CREDIT)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->sum('amount');

        $totalDebit = (clone $query)
            ->where('transaction_type', WalletTransaction::TYPE_DEBIT)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->sum('amount');

        $transactionCount = (clone $query)->count();

        $byType = (clone $query)
            ->select('transaction_type', DB::raw('COUNT(*) as total_records'), DB::raw('SUM(amount) as total_amount'))
            ->groupBy('transaction_type')
            ->orderBy('transaction_type')
            ->get();

        $bySource = (clone $query)
            ->select('source_type', DB::raw('COUNT(*) as total_records'), DB::raw('SUM(amount) as total_amount'))
            ->groupBy('source_type')
            ->orderBy('source_type')
            ->get();

        return $this->sendResponse([
            'total_credit' => $totalCredit,
            'total_debit' => $totalDebit,
            'net_balance_effect' => $totalCredit - $totalDebit,
            'transaction_count' => $transactionCount,
            'by_type' => $byType,
            'by_source' => $bySource,
        ], 'Wallet transaction summary retrieved successfully.');
    }

    /**
     * Generate unique transaction number.
     */
    private function generateTransactionNumber(): string
    {
        do {
            $number = 'WTR-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
        } while (WalletTransaction::where('transaction_number', $number)->exists());

        return $number;
    }
}