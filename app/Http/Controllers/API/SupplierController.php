<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SupplierController extends BaseController
{
    /**
     * Display suppliers.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Supplier::query()
            ->with([
                'createdBy:id,name,email,phone,role',
                'updatedBy:id,name,email,phone,role',
            ])
            ->orderBy('name');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('city')) {
            $query->where('city', $request->city);
        }

        if ($request->filled('country')) {
            $query->where('country', $request->country);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('supplier_code', 'like', '%' . $request->search . '%')
                    ->orWhere('name', 'like', '%' . $request->search . '%')
                    ->orWhere('contact_person', 'like', '%' . $request->search . '%')
                    ->orWhere('email', 'like', '%' . $request->search . '%')
                    ->orWhere('phone', 'like', '%' . $request->search . '%')
                    ->orWhere('tin_number', 'like', '%' . $request->search . '%');
            });
        }

        $perPage = $request->get('per_page', 20);

        $suppliers = $query->paginate($perPage);

        return $this->sendResponse($suppliers, 'Suppliers retrieved successfully.');
    }

    /**
     * Store supplier.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can create suppliers.');
        }

        $validator = Validator::make($request->all(), [
            'supplier_code' => 'nullable|string|max:50|unique:suppliers,supplier_code',
            'name' => 'required|string|max:255|unique:suppliers,name',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255|unique:suppliers,email',
            'phone' => 'nullable|string|max:50',
            'alternate_phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'tin_number' => 'nullable|string|max:100',
            'payment_terms' => 'nullable|string|max:255',
            'opening_balance' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:active,inactive',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $supplier = Supplier::create([
            'supplier_code' => $request->supplier_code ?? $this->generateSupplierCode(),
            'name' => $request->name,
            'contact_person' => $request->contact_person,
            'email' => $request->email,
            'phone' => $request->phone,
            'alternate_phone' => $request->alternate_phone,
            'address' => $request->address,
            'city' => $request->city,
            'country' => $request->country ?? 'Rwanda',
            'tin_number' => $request->tin_number,
            'payment_terms' => $request->payment_terms,
            'opening_balance' => $request->opening_balance ?? 0,
            'status' => $request->status ?? Supplier::STATUS_ACTIVE,
            'notes' => $request->notes,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $supplier->load([
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendCreated($supplier, 'Supplier created successfully.');
    }

    /**
     * Display one supplier.
     */
    public function show(Supplier $supplier): JsonResponse
    {
        $supplier->load([
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($supplier, 'Supplier retrieved successfully.');
    }

    /**
     * Update supplier.
     */
    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can update suppliers.');
        }

        $validator = Validator::make($request->all(), [
            'supplier_code' => 'nullable|string|max:50|unique:suppliers,supplier_code,' . $supplier->id,
            'name' => 'required|string|max:255|unique:suppliers,name,' . $supplier->id,
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255|unique:suppliers,email,' . $supplier->id,
            'phone' => 'nullable|string|max:50',
            'alternate_phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'tin_number' => 'nullable|string|max:100',
            'payment_terms' => 'nullable|string|max:255',
            'opening_balance' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:active,inactive',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $supplier->update([
            'supplier_code' => $request->supplier_code ?? $supplier->supplier_code,
            'name' => $request->name,
            'contact_person' => $request->contact_person,
            'email' => $request->email,
            'phone' => $request->phone,
            'alternate_phone' => $request->alternate_phone,
            'address' => $request->address,
            'city' => $request->city,
            'country' => $request->country ?? $supplier->country,
            'tin_number' => $request->tin_number,
            'payment_terms' => $request->payment_terms,
            'opening_balance' => $request->opening_balance ?? $supplier->opening_balance,
            'status' => $request->status ?? $supplier->status,
            'notes' => $request->notes,
            'updated_by' => $request->user()->id,
        ]);

        $supplier->load([
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($supplier, 'Supplier updated successfully.');
    }

    /**
     * Delete supplier.
     */
    public function destroy(Request $request, Supplier $supplier): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can delete suppliers.');
        }

        $supplier->delete();

        return $this->sendResponse([], 'Supplier deleted successfully.');
    }

    /**
     * Restore deleted supplier.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden('Only admin or staff can restore suppliers.');
        }

        $supplier = Supplier::onlyTrashed()->find($id);

        if (!$supplier) {
            return $this->sendNotFound('Deleted supplier not found.');
        }

        $supplier->restore();

        $supplier->load([
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($supplier, 'Supplier restored successfully.');
    }

    /**
     * Supplier summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $query = Supplier::query();

        if ($request->filled('country')) {
            $query->where('country', $request->country);
        }

        if ($request->filled('city')) {
            $query->where('city', $request->city);
        }

        $totalSuppliers = (clone $query)->count();

        $activeSuppliers = (clone $query)
            ->where('status', Supplier::STATUS_ACTIVE)
            ->count();

        $inactiveSuppliers = (clone $query)
            ->where('status', Supplier::STATUS_INACTIVE)
            ->count();

        $openingBalanceTotal = (clone $query)->sum('opening_balance');

        $byStatus = (clone $query)
            ->select('status', DB::raw('COUNT(*) as total_records'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        $byCountry = (clone $query)
            ->select('country', DB::raw('COUNT(*) as total_records'))
            ->groupBy('country')
            ->orderBy('country')
            ->get();

        return $this->sendResponse([
            'total_suppliers' => $totalSuppliers,
            'active_suppliers' => $activeSuppliers,
            'inactive_suppliers' => $inactiveSuppliers,
            'opening_balance_total' => $openingBalanceTotal,
            'by_status' => $byStatus,
            'by_country' => $byCountry,
        ], 'Supplier summary retrieved successfully.');
    }

    /**
     * Generate unique supplier code.
     */
    private function generateSupplierCode(): string
    {
        do {
            $code = 'SUP-' . now()->format('Ymd') . '-' . strtoupper(Str::random(5));
        } while (Supplier::where('supplier_code', $code)->exists());

        return $code;
    }
}