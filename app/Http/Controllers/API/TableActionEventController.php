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
     * Public live activity feed.
     */
    public function publicIndex(Request $request): JsonResponse
    {
        $limit = min(
            max((int) $request->integer('limit', 100), 1),
            200
        );

        $query = TableActionEvent::query()
            ->with([
                'table:id,table_number,name,location,status',
            ])
            ->latest('id');

        if ($request->filled('action')) {
            $query->where(
                'action',
                (string) $request->query('action')
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

        $summaryQuery = TableActionEvent::query()
            ->whereDate('occurred_at', $today);

        $summary = [
            'all' => (clone $summaryQuery)->count(),

            'order' => (clone $summaryQuery)
                ->where(
                    'action',
                    TableActionEvent::ACTION_ORDER
                )
                ->count(),

            'call' => (clone $summaryQuery)
                ->where(
                    'action',
                    TableActionEvent::ACTION_CALL
                )
                ->count(),

            'pay' => (clone $summaryQuery)
                ->where(
                    'action',
                    TableActionEvent::ACTION_PAY
                )
                ->count(),

            'cancel' => (clone $summaryQuery)
                ->where(
                    'action',
                    TableActionEvent::ACTION_CANCEL
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
     * Store a public action from a table QR screen.
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
                'Table not found.',
                [],
                404
            );
        }

        if ($table->status === 'inactive') {
            return $this->sendError(
                'This table is inactive.',
                [],
                422
            );
        }

        $action = (string) $request->input('action');

        $event = TableActionEvent::query()->create([
            'canteen_table_id' => $table->id,

            'action' => $action,

            'status' =>
                $action === TableActionEvent::ACTION_CANCEL
                    ? TableActionEvent::STATUS_CANCELLED
                    : TableActionEvent::STATUS_PENDING,

            'message' => $request->input('message'),

            'source_ip' => $request->ip(),

            'user_agent' => substr(
                (string) $request->userAgent(),
                0,
                1000
            ),

            'occurred_at' => now(),
        ]);

        $event->load([
            'table:id,table_number,name,location,status',
        ]);

        return $this->sendResponse(
            $event,
            'Table action received successfully.'
        );
    }
}
