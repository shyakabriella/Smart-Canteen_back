<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\CanteenTable;
use App\Models\TableActionEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TableActionEventController extends BaseController
{
    /**
     * Display public table activity.
     *
     * GET /api/table-action-events/public
     *
     * Supported query parameters:
     * - action=order|call|pay|cancel
     * - status=pending|acknowledged|completed|cancelled
     * - table_id=1
     * - after_id=10
     * - limit=100
     */
    public function publicIndex(Request $request): JsonResponse
    {
        $validator = Validator::make(
            $request->query(),
            [
                'action' => [
                    'nullable',
                    Rule::in([
                        TableActionEvent::ACTION_ORDER,
                        TableActionEvent::ACTION_CALL,
                        TableActionEvent::ACTION_PAY,
                        TableActionEvent::ACTION_CANCEL,
                    ]),
                ],

                'status' => [
                    'nullable',
                    Rule::in([
                        TableActionEvent::STATUS_PENDING,
                        TableActionEvent::STATUS_ACKNOWLEDGED,
                        TableActionEvent::STATUS_COMPLETED,
                        TableActionEvent::STATUS_CANCELLED,
                    ]),
                ],

                'table_id' => [
                    'nullable',
                    'integer',
                    'min:1',
                ],

                'after_id' => [
                    'nullable',
                    'integer',
                    'min:1',
                ],

                'limit' => [
                    'nullable',
                    'integer',
                    'min:1',
                    'max:200',
                ],
            ]
        );

        if ($validator->fails()) {
            return $this->sendError(
                'Validation failed.',
                $validator->errors(),
                422
            );
        }

        $limit = (int) $request->integer('limit', 100);

        $query = TableActionEvent::query()
            ->with([
                'table:id,table_number,name,location,capacity,status,qr_token',
            ])
            ->orderByDesc('id');

        if ($request->filled('action')) {
            $query->where(
                'action',
                (string) $request->query('action')
            );
        }

        if ($request->filled('status')) {
            $query->where(
                'status',
                (string) $request->query('status')
            );
        }

        if ($request->filled('table_id')) {
            $query->where(
                'canteen_table_id',
                (int) $request->query('table_id')
            );
        }

        if ($request->filled('after_id')) {
            $query->where(
                'id',
                '>',
                (int) $request->query('after_id')
            );
        }

        $events = $query
            ->limit($limit)
            ->get();

        $today = now()->toDateString();

        $todayQuery = TableActionEvent::query()
            ->whereDate('occurred_at', $today);

        $summary = [
            'all' => (clone $todayQuery)->count(),

            'order' => (clone $todayQuery)
                ->where(
                    'action',
                    TableActionEvent::ACTION_ORDER
                )
                ->count(),

            'call' => (clone $todayQuery)
                ->where(
                    'action',
                    TableActionEvent::ACTION_CALL
                )
                ->count(),

            'pay' => (clone $todayQuery)
                ->where(
                    'action',
                    TableActionEvent::ACTION_PAY
                )
                ->count(),

            'cancel' => (clone $todayQuery)
                ->where(
                    'action',
                    TableActionEvent::ACTION_CANCEL
                )
                ->count(),

            'pending' => (clone $todayQuery)
                ->where(
                    'status',
                    TableActionEvent::STATUS_PENDING
                )
                ->count(),

            'acknowledged' => (clone $todayQuery)
                ->where(
                    'status',
                    TableActionEvent::STATUS_ACKNOWLEDGED
                )
                ->count(),

            'completed' => (clone $todayQuery)
                ->where(
                    'status',
                    TableActionEvent::STATUS_COMPLETED
                )
                ->count(),

            'cancelled' => (clone $todayQuery)
                ->where(
                    'status',
                    TableActionEvent::STATUS_CANCELLED
                )
                ->count(),
        ];

        return $this->sendResponse(
            [
                'events' => $events,
                'summary' => $summary,
                'server_time' => now()->toIso8601String(),
            ],
            'Table activity loaded successfully.'
        );
    }

    /**
     * Store a public action sent from a table QR screen.
     *
     * POST /api/canteen-tables/public/{qrToken}/actions
     *
     * Request body:
     * {
     *   "action": "call",
     *   "message": "Optional message"
     * }
     */
    public function storePublic(
        Request $request,
        string $qrToken
    ): JsonResponse {
        $validator = Validator::make(
            $request->all(),
            [
                'action' => [
                    'required',
                    Rule::in([
                        TableActionEvent::ACTION_ORDER,
                        TableActionEvent::ACTION_CALL,
                        TableActionEvent::ACTION_PAY,
                        TableActionEvent::ACTION_CANCEL,
                    ]),
                ],

                'message' => [
                    'nullable',
                    'string',
                    'max:255',
                ],
            ]
        );

        if ($validator->fails()) {
            return $this->sendError(
                'Validation failed.',
                $validator->errors(),
                422
            );
        }

        $table = CanteenTable::query()
            ->where('qr_token', $qrToken)
            ->first();

        if (!$table) {
            return $this->sendError(
                'Canteen table not found.',
                [],
                404
            );
        }

        if (
            strtolower((string) $table->status) === 'inactive'
        ) {
            return $this->sendError(
                'This canteen table is inactive.',
                [],
                422
            );
        }

        $action = (string) $request->input('action');

        $status = $action === TableActionEvent::ACTION_CANCEL
            ? TableActionEvent::STATUS_CANCELLED
            : TableActionEvent::STATUS_PENDING;

        $defaultMessage = match ($action) {
            TableActionEvent::ACTION_ORDER =>
                "Table {$table->table_number} requested to place an order.",

            TableActionEvent::ACTION_CALL =>
                "Table {$table->table_number} requested staff assistance.",

            TableActionEvent::ACTION_PAY =>
                "Table {$table->table_number} requested payment assistance.",

            TableActionEvent::ACTION_CANCEL =>
                "Table {$table->table_number} cancelled the current request.",

            default =>
                "Table {$table->table_number} sent an action.",
        };

        $event = TableActionEvent::query()->create([
            'canteen_table_id' => $table->id,

            'action' => $action,

            'status' => $status,

            'message' => $request->filled('message')
                ? (string) $request->input('message')
                : $defaultMessage,

            'source_ip' => $request->ip(),

            'user_agent' => substr(
                (string) $request->userAgent(),
                0,
                1000
            ),

            'occurred_at' => now(),
        ]);

        $event->load([
            'table:id,table_number,name,location,capacity,status,qr_token',
        ]);

        return $this->sendResponse(
            $event,
            'Table action received successfully.'
        );
    }

    /**
     * Display protected table activity.
     *
     * GET /api/table-action-events
     */
    public function index(Request $request): JsonResponse
    {
        $query = TableActionEvent::query()
            ->with([
                'table:id,table_number,name,location,capacity,status,qr_token',
            ])
            ->orderByDesc('id');

        if ($request->filled('action')) {
            $query->where(
                'action',
                (string) $request->query('action')
            );
        }

        if ($request->filled('status')) {
            $query->where(
                'status',
                (string) $request->query('status')
            );
        }

        if ($request->filled('table_id')) {
            $query->where(
                'canteen_table_id',
                (int) $request->query('table_id')
            );
        }

        $perPage = min(
            max((int) $request->integer('per_page', 20), 1),
            100
        );

        $events = $query->paginate($perPage);

        return $this->sendResponse(
            $events,
            'Table action events loaded successfully.'
        );
    }

    /**
     * Display one event.
     *
     * GET /api/table-action-events/{tableActionEvent}
     */
    public function show(
        TableActionEvent $tableActionEvent
    ): JsonResponse {
        $tableActionEvent->load([
            'table:id,table_number,name,location,capacity,status,qr_token',
        ]);

        return $this->sendResponse(
            $tableActionEvent,
            'Table action event loaded successfully.'
        );
    }

    /**
     * Mark an action as acknowledged.
     *
     * POST /api/table-action-events/{tableActionEvent}/acknowledge
     */
    public function acknowledge(
        TableActionEvent $tableActionEvent
    ): JsonResponse {
        if (
            $tableActionEvent->status ===
            TableActionEvent::STATUS_CANCELLED
        ) {
            return $this->sendError(
                'A cancelled action cannot be acknowledged.',
                [],
                422
            );
        }

        $tableActionEvent->update([
            'status' =>
                TableActionEvent::STATUS_ACKNOWLEDGED,
            'handled_at' => now(),
        ]);

        $tableActionEvent->load([
            'table:id,table_number,name,location,capacity,status,qr_token',
        ]);

        return $this->sendResponse(
            $tableActionEvent,
            'Table action acknowledged successfully.'
        );
    }

    /**
     * Mark an action as completed.
     *
     * POST /api/table-action-events/{tableActionEvent}/complete
     */
    public function complete(
        TableActionEvent $tableActionEvent
    ): JsonResponse {
        if (
            $tableActionEvent->status ===
            TableActionEvent::STATUS_CANCELLED
        ) {
            return $this->sendError(
                'A cancelled action cannot be completed.',
                [],
                422
            );
        }

        $tableActionEvent->update([
            'status' =>
                TableActionEvent::STATUS_COMPLETED,
            'handled_at' => now(),
        ]);

        $tableActionEvent->load([
            'table:id,table_number,name,location,capacity,status,qr_token',
        ]);

        return $this->sendResponse(
            $tableActionEvent,
            'Table action completed successfully.'
        );
    }

    /**
     * Cancel an existing action.
     *
     * POST /api/table-action-events/{tableActionEvent}/cancel
     */
    public function cancel(
        TableActionEvent $tableActionEvent
    ): JsonResponse {
        $tableActionEvent->update([
            'status' =>
                TableActionEvent::STATUS_CANCELLED,
            'handled_at' => now(),
        ]);

        $tableActionEvent->load([
            'table:id,table_number,name,location,capacity,status,qr_token',
        ]);

        return $this->sendResponse(
            $tableActionEvent,
            'Table action cancelled successfully.'
        );
    }

    /**
     * Delete an action event.
     *
     * DELETE /api/table-action-events/{tableActionEvent}
     */
    public function destroy(
        TableActionEvent $tableActionEvent
    ): JsonResponse {
        $tableActionEvent->delete();

        return $this->sendResponse(
            [],
            'Table action event deleted successfully.'
        );
    }
}