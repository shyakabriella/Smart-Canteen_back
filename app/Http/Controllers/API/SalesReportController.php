<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SalesReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SalesReportController extends BaseController
{
    /**
     * Display sales reports.
     */
    public function index(Request $request): JsonResponse
    {
        $query = SalesReport::query()
            ->with([
                'generatedBy:id,name,email,phone,role',
                'finalizedBy:id,name,email,phone,role',
                'createdBy:id,name,email,phone,role',
                'updatedBy:id,name,email,phone,role',
            ])
            ->orderByDesc('id');

        if ($request->filled('report_type')) {
            $query->where('report_type', $request->report_type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('generated_by')) {
            $query->where('generated_by', $request->generated_by);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('period_start', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('period_end', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('report_number', 'like', '%' . $request->search . '%')
                    ->orWhere('title', 'like', '%' . $request->search . '%')
                    ->orWhere('notes', 'like', '%' . $request->search . '%');
            });
        }

        $perPage = $request->get('per_page', 20);

        $reports = $query->paginate($perPage);

        return $this->sendResponse($reports, 'Sales reports retrieved successfully.');
    }

    /**
     * Store/generate sales report.
     */
    public function store(Request $request): JsonResponse
    {
        return $this->generate($request);
    }

    /**
     * Generate sales report from orders.
     */
    public function generate(Request $request): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can generate sales reports.');
        }

        $validator = Validator::make($request->all(), [
            'report_type' => 'nullable|in:daily,weekly,monthly,custom',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'title' => 'nullable|string|max:255',
            'status' => 'nullable|in:draft,final',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $data = $this->buildReportData(
            $request->period_start,
            $request->period_end
        );

        $report = SalesReport::create([
            'report_number' => $this->generateReportNumber(),
            'title' => $request->title ?? $this->buildReportTitle(
                $request->report_type ?? SalesReport::TYPE_DAILY,
                $request->period_start,
                $request->period_end
            ),
            'report_type' => $request->report_type ?? SalesReport::TYPE_DAILY,
            'period_start' => $request->period_start,
            'period_end' => $request->period_end,

            'total_orders' => $data['total_orders'],
            'pending_orders' => $data['pending_orders'],
            'confirmed_orders' => $data['confirmed_orders'],
            'preparing_orders' => $data['preparing_orders'],
            'ready_orders' => $data['ready_orders'],
            'completed_orders' => $data['completed_orders'],
            'cancelled_orders' => $data['cancelled_orders'],

            'paid_orders' => $data['paid_orders'],
            'refunded_orders' => $data['refunded_orders'],
            'unpaid_orders' => $data['unpaid_orders'],

            'gross_sales' => $data['gross_sales'],
            'discount_amount' => $data['discount_amount'],
            'tax_amount' => $data['tax_amount'],
            'refund_amount' => $data['refund_amount'],
            'net_sales' => $data['net_sales'],

            'wallet_sales' => $data['wallet_sales'],
            'cash_sales' => $data['cash_sales'],
            'mobile_money_sales' => $data['mobile_money_sales'],

            'total_items_sold' => $data['total_items_sold'],
            'total_quantity_sold' => $data['total_quantity_sold'],
            'average_order_value' => $data['average_order_value'],

            'status' => $request->status ?? SalesReport::STATUS_DRAFT,
            'report_data' => $data['report_data'],
            'notes' => $request->notes,

            'generated_by' => $request->user()->id,
            'generated_at' => now(),

            'finalized_by' => ($request->status === SalesReport::STATUS_FINAL)
                ? $request->user()->id
                : null,

            'finalized_at' => ($request->status === SalesReport::STATUS_FINAL)
                ? now()
                : null,

            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $report->load($this->defaultRelations());

        return $this->sendCreated($report, 'Sales report generated successfully.');
    }

    /**
     * Display one sales report.
     */
    public function show(SalesReport $salesReport): JsonResponse
    {
        $salesReport->load($this->defaultRelations());

        return $this->sendResponse($salesReport, 'Sales report retrieved successfully.');
    }

    /**
     * Update sales report title/notes/status.
     */
    public function update(Request $request, SalesReport $salesReport): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can update sales reports.');
        }

        if ($salesReport->isFinal()) {
            return $this->sendError('Finalized reports cannot be updated. Regenerate a new report instead.', [], 400);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:draft,cancelled',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $salesReport->update([
            'title' => $request->title ?? $salesReport->title,
            'notes' => $request->notes ?? $salesReport->notes,
            'status' => $request->status ?? $salesReport->status,
            'updated_by' => $request->user()->id,
        ]);

        $salesReport->load($this->defaultRelations());

        return $this->sendResponse($salesReport, 'Sales report updated successfully.');
    }

    /**
     * Regenerate existing sales report using same period.
     */
    public function regenerate(Request $request, SalesReport $salesReport): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can regenerate sales reports.');
        }

        if ($salesReport->isFinal()) {
            return $this->sendError('Finalized reports cannot be regenerated.', [], 400);
        }

        $data = $this->buildReportData(
            $salesReport->period_start->format('Y-m-d'),
            $salesReport->period_end->format('Y-m-d')
        );

        $salesReport->update([
            'total_orders' => $data['total_orders'],
            'pending_orders' => $data['pending_orders'],
            'confirmed_orders' => $data['confirmed_orders'],
            'preparing_orders' => $data['preparing_orders'],
            'ready_orders' => $data['ready_orders'],
            'completed_orders' => $data['completed_orders'],
            'cancelled_orders' => $data['cancelled_orders'],

            'paid_orders' => $data['paid_orders'],
            'refunded_orders' => $data['refunded_orders'],
            'unpaid_orders' => $data['unpaid_orders'],

            'gross_sales' => $data['gross_sales'],
            'discount_amount' => $data['discount_amount'],
            'tax_amount' => $data['tax_amount'],
            'refund_amount' => $data['refund_amount'],
            'net_sales' => $data['net_sales'],

            'wallet_sales' => $data['wallet_sales'],
            'cash_sales' => $data['cash_sales'],
            'mobile_money_sales' => $data['mobile_money_sales'],

            'total_items_sold' => $data['total_items_sold'],
            'total_quantity_sold' => $data['total_quantity_sold'],
            'average_order_value' => $data['average_order_value'],

            'report_data' => $data['report_data'],
            'generated_by' => $request->user()->id,
            'generated_at' => now(),
            'updated_by' => $request->user()->id,
        ]);

        $salesReport->load($this->defaultRelations());

        return $this->sendResponse($salesReport, 'Sales report regenerated successfully.');
    }

    /**
     * Finalize report.
     */
    public function finalize(Request $request, SalesReport $salesReport): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can finalize sales reports.');
        }

        if ($salesReport->isFinal()) {
            return $this->sendError('Sales report is already finalized.', [], 400);
        }

        if ($salesReport->isCancelled()) {
            return $this->sendError('Cancelled sales report cannot be finalized.', [], 400);
        }

        $salesReport->update([
            'status' => SalesReport::STATUS_FINAL,
            'finalized_by' => $request->user()->id,
            'finalized_at' => now(),
            'updated_by' => $request->user()->id,
        ]);

        $salesReport->load($this->defaultRelations());

        return $this->sendResponse($salesReport, 'Sales report finalized successfully.');
    }

    /**
     * Delete sales report.
     */
    public function destroy(Request $request, SalesReport $salesReport): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can delete sales reports.');
        }

        if ($salesReport->isFinal()) {
            return $this->sendError('Finalized sales reports cannot be deleted.', [], 400);
        }

        $salesReport->delete();

        return $this->sendResponse([], 'Sales report deleted successfully.');
    }

    /**
     * Restore deleted sales report.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageOrders()) {
            return $this->sendForbidden('Only admin or staff can restore sales reports.');
        }

        $report = SalesReport::onlyTrashed()->find($id);

        if (!$report) {
            return $this->sendNotFound('Deleted sales report not found.');
        }

        $report->restore();

        $report->load($this->defaultRelations());

        return $this->sendResponse($report, 'Sales report restored successfully.');
    }

    /**
     * Sales report summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $query = SalesReport::query();

        if ($request->filled('from_date')) {
            $query->whereDate('period_start', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('period_end', '<=', $request->to_date);
        }

        if ($request->filled('report_type')) {
            $query->where('report_type', $request->report_type);
        }

        $totalReports = (clone $query)->count();

        $draftReports = (clone $query)
            ->where('status', SalesReport::STATUS_DRAFT)
            ->count();

        $finalReports = (clone $query)
            ->where('status', SalesReport::STATUS_FINAL)
            ->count();

        $grossSales = (clone $query)->sum('gross_sales');
        $refundAmount = (clone $query)->sum('refund_amount');
        $netSales = (clone $query)->sum('net_sales');
        $walletSales = (clone $query)->sum('wallet_sales');

        $byType = (clone $query)
            ->select('report_type', DB::raw('COUNT(*) as total_records'))
            ->groupBy('report_type')
            ->orderBy('report_type')
            ->get();

        $byStatus = (clone $query)
            ->select('status', DB::raw('COUNT(*) as total_records'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        return $this->sendResponse([
            'total_reports' => $totalReports,
            'draft_reports' => $draftReports,
            'final_reports' => $finalReports,
            'gross_sales' => $grossSales,
            'refund_amount' => $refundAmount,
            'net_sales' => $netSales,
            'wallet_sales' => $walletSales,
            'by_type' => $byType,
            'by_status' => $byStatus,
        ], 'Sales report summary retrieved successfully.');
    }

    /**
     * Build report data from orders and order items.
     */
    private function buildReportData(string $periodStart, string $periodEnd): array
    {
        $ordersQuery = Order::query()
            ->whereDate('ordered_at', '>=', $periodStart)
            ->whereDate('ordered_at', '<=', $periodEnd);

        $totalOrders = (clone $ordersQuery)->count();

        $pendingOrders = (clone $ordersQuery)
            ->where('order_status', Order::STATUS_PENDING)
            ->count();

        $confirmedOrders = (clone $ordersQuery)
            ->where('order_status', Order::STATUS_CONFIRMED)
            ->count();

        $preparingOrders = (clone $ordersQuery)
            ->where('order_status', Order::STATUS_PREPARING)
            ->count();

        $readyOrders = (clone $ordersQuery)
            ->where('order_status', Order::STATUS_READY)
            ->count();

        $completedOrders = (clone $ordersQuery)
            ->where('order_status', Order::STATUS_COMPLETED)
            ->count();

        $cancelledOrders = (clone $ordersQuery)
            ->where('order_status', Order::STATUS_CANCELLED)
            ->count();

        $paidOrders = (clone $ordersQuery)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->count();

        $refundedOrders = (clone $ordersQuery)
            ->where('payment_status', Order::PAYMENT_REFUNDED)
            ->count();

        $unpaidOrders = (clone $ordersQuery)
            ->whereNotIn('payment_status', [
                Order::PAYMENT_PAID,
                Order::PAYMENT_REFUNDED,
            ])
            ->count();

        // Gross sales include paid orders and refunded orders before refund deduction.
        $grossSales = (clone $ordersQuery)
            ->whereIn('payment_status', [
                Order::PAYMENT_PAID,
                Order::PAYMENT_REFUNDED,
            ])
            ->sum('paid_amount');

        $refundAmount = (clone $ordersQuery)
            ->where('payment_status', Order::PAYMENT_REFUNDED)
            ->sum('paid_amount');

        $netSales = $grossSales - $refundAmount;

        $discountAmount = (clone $ordersQuery)->sum('discount_amount');
        $taxAmount = (clone $ordersQuery)->sum('tax_amount');

        $walletSales = (clone $ordersQuery)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->where('payment_method', Order::PAYMENT_METHOD_WALLET)
            ->sum('paid_amount');

        $cashSales = (clone $ordersQuery)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->where('payment_method', Order::PAYMENT_METHOD_CASH)
            ->sum('paid_amount');

        $mobileMoneySales = (clone $ordersQuery)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->where('payment_method', Order::PAYMENT_METHOD_MOBILE_MONEY)
            ->sum('paid_amount');

        $averageOrderValue = $paidOrders > 0
            ? round($netSales / $paidOrders, 2)
            : 0;

        $soldItemsQuery = OrderItem::query()
            ->whereHas('order', function ($query) use ($periodStart, $periodEnd) {
                $query->whereDate('ordered_at', '>=', $periodStart)
                    ->whereDate('ordered_at', '<=', $periodEnd)
                    ->where('payment_status', Order::PAYMENT_PAID);
            })
            ->where('item_status', '!=', OrderItem::STATUS_CANCELLED);

        $totalItemsSold = (clone $soldItemsQuery)->count();
        $totalQuantitySold = (clone $soldItemsQuery)->sum('quantity');

        $ordersByStatus = (clone $ordersQuery)
            ->select('order_status', DB::raw('COUNT(*) as total_records'))
            ->groupBy('order_status')
            ->orderBy('order_status')
            ->get();

        $ordersByPaymentStatus = (clone $ordersQuery)
            ->select('payment_status', DB::raw('COUNT(*) as total_records'), DB::raw('SUM(paid_amount) as total_paid'))
            ->groupBy('payment_status')
            ->orderBy('payment_status')
            ->get();

        $ordersByPaymentMethod = (clone $ordersQuery)
            ->select('payment_method', DB::raw('COUNT(*) as total_records'), DB::raw('SUM(paid_amount) as total_paid'))
            ->where('payment_status', Order::PAYMENT_PAID)
            ->groupBy('payment_method')
            ->orderBy('payment_method')
            ->get();

        $topItems = OrderItem::query()
            ->select(
                'food_item_id',
                'food_name',
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('SUM(total_amount) as total_amount')
            )
            ->whereHas('order', function ($query) use ($periodStart, $periodEnd) {
                $query->whereDate('ordered_at', '>=', $periodStart)
                    ->whereDate('ordered_at', '<=', $periodEnd)
                    ->where('payment_status', Order::PAYMENT_PAID);
            })
            ->where('item_status', '!=', OrderItem::STATUS_CANCELLED)
            ->groupBy('food_item_id', 'food_name')
            ->orderByDesc('total_quantity')
            ->limit(10)
            ->get();

        return [
            'total_orders' => $totalOrders,
            'pending_orders' => $pendingOrders,
            'confirmed_orders' => $confirmedOrders,
            'preparing_orders' => $preparingOrders,
            'ready_orders' => $readyOrders,
            'completed_orders' => $completedOrders,
            'cancelled_orders' => $cancelledOrders,

            'paid_orders' => $paidOrders,
            'refunded_orders' => $refundedOrders,
            'unpaid_orders' => $unpaidOrders,

            'gross_sales' => $grossSales,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'refund_amount' => $refundAmount,
            'net_sales' => $netSales,

            'wallet_sales' => $walletSales,
            'cash_sales' => $cashSales,
            'mobile_money_sales' => $mobileMoneySales,

            'total_items_sold' => $totalItemsSold,
            'total_quantity_sold' => $totalQuantitySold,
            'average_order_value' => $averageOrderValue,

            'report_data' => [
                'orders_by_status' => $ordersByStatus,
                'orders_by_payment_status' => $ordersByPaymentStatus,
                'orders_by_payment_method' => $ordersByPaymentMethod,
                'top_items' => $topItems,
            ],
        ];
    }

    /**
     * Default relations.
     */
    private function defaultRelations(): array
    {
        return [
            'generatedBy:id,name,email,phone,role',
            'finalizedBy:id,name,email,phone,role',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ];
    }

    /**
     * Generate unique report number.
     */
    private function generateReportNumber(): string
    {
        do {
            $number = 'SR-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
        } while (SalesReport::where('report_number', $number)->exists());

        return $number;
    }

    /**
     * Build default report title.
     */
    private function buildReportTitle(string $reportType, string $periodStart, string $periodEnd): string
    {
        return ucfirst($reportType) . ' Sales Report: ' . $periodStart . ' to ' . $periodEnd;
    }
}