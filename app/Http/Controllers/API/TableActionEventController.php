<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\CanteenTable;
use App\Models\TableActionEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TableActionEventController extends BaseController
{
    /**
     * Public TV request feed.
     *
     * By default, this endpoint returns pending requests only.
     *
     * GET /api/table-action-events/public
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

        $limit = min(
            max((int) $request->integer('limit', 100), 1),
            200
        );

        $status = (string) $request->query(
            'status',
            TableActionEvent::STATUS_PENDING
        );

        $query = TableActionEvent::query()
            ->with([
                'table:id,table_number,name,location,capacity,status,qr_token',
            ])
            ->where('status', $status)
            ->orderBy('occurred_at')
            ->orderBy('id');

        if ($request->filled('action')) {
            $query->where(
                'action',
                (string) $request->query('action')
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

        $pendingQuery = TableActionEvent::query()
            ->where(
                'status',
                TableActionEvent::STATUS_PENDING
            );

        $summary = [
            'all' => (clone $pendingQuery)->count(),

            'order' => (clone $pendingQuery)
                ->where(
                    'action',
                    TableActionEvent::ACTION_ORDER
                )
                ->count(),

            'call' => (clone $pendingQuery)
                ->where(
                    'action',
                    TableActionEvent::ACTION_CALL
                )
                ->count(),

            'pay' => (clone $pendingQuery)
                ->where(
                    'action',
                    TableActionEvent::ACTION_PAY
                )
                ->count(),
        ];

        return $this->sendResponse(
            [
                'events' => $events,
                'summary' => $summary,
                'server_time' => now()->toIso8601String(),
            ],
            'Active table requests loaded successfully.'
        );
    }

    /**
     * Receive a public action from the table QR screen.
     *
     * POST /api/canteen-tables/public/{qrToken}/actions
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

        if (
            $action === TableActionEvent::ACTION_CANCEL
        ) {
            return DB::transaction(function () use (
                $request,
                $table
            ): JsonResponse {
                TableActionEvent::query()
                    ->where(
                        'canteen_table_id',
                        $table->id
                    )
                    ->whereIn(
                        'status',
                        [
                            TableActionEvent::STATUS_PENDING,
                            TableActionEvent::STATUS_ACKNOWLEDGED,
                        ]
                    )
                    ->update([
                        'status' =>
                            TableActionEvent::STATUS_CANCELLED,
                        'handled_at' => now(),
                        'updated_at' => now(),
                    ]);

                $event = TableActionEvent::query()->create([
                    'canteen_table_id' => $table->id,
                    'action' =>
                        TableActionEvent::ACTION_CANCEL,
                    'status' =>
                        TableActionEvent::STATUS_CANCELLED,
                    'message' => $request->filled('message')
                        ? (string) $request->input('message')
                        : "Table {$table->table_number} cancelled its active request.",
                    'source_ip' => $request->ip(),
                    'user_agent' => substr(
                        (string) $request->userAgent(),
                        0,
                        1000
                    ),
                    'occurred_at' => now(),
                    'handled_at' => now(),
                ]);

                $event->load([
                    'table:id,table_number,name,location,capacity,status,qr_token',
                ]);

                return $this->sendResponse(
                    $event,
                    'Active requests for this table were cancelled.'
                );
            });
        }

        /*
         * Keep only one pending request for the same
         * table and action. Repeated taps refresh the
         * existing event instead of filling the TV.
         */
        $existingEvent = TableActionEvent::query()
            ->where(
                'canteen_table_id',
                $table->id
            )
            ->where('action', $action)
            ->where(
                'status',
                TableActionEvent::STATUS_PENDING
            )
            ->latest('id')
            ->first();

        $defaultMessage = match ($action) {
            TableActionEvent::ACTION_ORDER =>
                "Table {$table->table_number} requested to place an order.",

            TableActionEvent::ACTION_CALL =>
                "Table {$table->table_number} requested staff assistance.",

            TableActionEvent::ACTION_PAY =>
                "Table {$table->table_number} requested payment assistance.",

            default =>
                "Table {$table->table_number} sent a request.",
        };

        if ($existingEvent) {
            $existingEvent->update([
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
                'handled_at' => null,
            ]);

            $existingEvent->load([
                'table:id,table_number,name,location,capacity,status,qr_token',
            ]);

            return $this->sendResponse(
                $existingEvent,
                'Table request refreshed successfully.'
            );
        }

        $event = TableActionEvent::query()->create([
            'canteen_table_id' => $table->id,
            'action' => $action,
            'status' =>
                TableActionEvent::STATUS_PENDING,
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
            'Table request received successfully.'
        );
    }

    /**
     * A waiter touches a request on the public TV.
     *
     * Once acknowledged, the request is no longer
     * returned by the pending public feed.
     *
     * POST /api/table-action-events/public/{tableActionEvent}/acknowledge
     */
    public function acknowledgePublic(
        TableActionEvent $tableActionEvent
    ): JsonResponse {
        if (
            $tableActionEvent->status !==
            TableActionEvent::STATUS_PENDING
        ) {
            $tableActionEvent->load([
                'table:id,table_number,name,location,capacity,status,qr_token',
            ]);

            return $this->sendResponse(
                $tableActionEvent,
                'This request has already been received.'
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
            'Request received successfully.'
        );
    }

    /**
     * Protected event list.
     */
    public function index(Request $request): JsonResponse
    {
        $query = TableActionEvent::query()
            ->with([
                'table:id,table_number,name,location,capacity,status,qr_token',
            ])
            ->latest('id');

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

        return $this->sendResponse(
            $query->paginate($perPage),
            'Table action events loaded successfully.'
        );
    }

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

    public function acknowledge(
        TableActionEvent $tableActionEvent
    ): JsonResponse {
        return $this->acknowledgePublic(
            $tableActionEvent
        );
    }

    public function complete(
        TableActionEvent $tableActionEvent
    ): JsonResponse {
        if (
            $tableActionEvent->status ===
            TableActionEvent::STATUS_CANCELLED
        ) {
            return $this->sendError(
                'A cancelled request cannot be completed.',
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
            'Table request completed successfully.'
        );
    }

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
            'Table request cancelled successfully.'
        );
    }

    public function destroy(
        TableActionEvent $tableActionEvent
    ): JsonResponse {
        $tableActionEvent->delete();

        return $this->sendResponse(
            [],
            'Table request deleted successfully.'
        );
    }
}
