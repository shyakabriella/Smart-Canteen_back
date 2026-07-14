<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\CanteenTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class CanteenTableController extends BaseController
{
    /**
     * Display all canteen tables.
     */
    public function index(Request $request): JsonResponse
    {
        $query = CanteenTable::query()
            ->with([
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ])
            ->orderBy('table_number');

        /*
         * Search by table number, name,
         * location or description.
         */
        if ($request->filled('search')) {
            $search = trim(
                (string) $request->input('search')
            );

            $query->where(function ($builder) use ($search) {
                $builder
                    ->where(
                        'table_number',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'name',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'location',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'description',
                        'like',
                        "%{$search}%"
                    );
            });
        }

        /*
         * Filter by table status.
         */
        if ($request->filled('status')) {
            $status = strtolower(
                trim((string) $request->input('status'))
            );

            if (in_array(
                $status,
                CanteenTable::statuses(),
                true
            )) {
                $query->where('status', $status);
            }
        }

        /*
         * Filter by location.
         */
        if ($request->filled('location')) {
            $query->where(
                'location',
                'like',
                '%' . trim(
                    (string) $request->input('location')
                ) . '%'
            );
        }

        /*
         * Admin may request deleted records.
         */
        if ($request->boolean('with_deleted')) {
            $query->withTrashed();
        }

        if ($request->boolean('only_deleted')) {
            $query->onlyTrashed();
        }

        $perPage = (int) $request->input(
            'per_page',
            20
        );

        $perPage = max(1, min($perPage, 200));

        $tables = $query->paginate($perPage);

        return $this->sendResponse(
            $tables,
            'Canteen tables retrieved successfully.'
        );
    }

    /**
     * Display table statistics.
     */
    public function summary(): JsonResponse
    {
        $summary = [
            'total_tables' => CanteenTable::count(),

            'available_tables' => CanteenTable::where(
                'status',
                CanteenTable::STATUS_AVAILABLE
            )->count(),

            'occupied_tables' => CanteenTable::where(
                'status',
                CanteenTable::STATUS_OCCUPIED
            )->count(),

            'reserved_tables' => CanteenTable::where(
                'status',
                CanteenTable::STATUS_RESERVED
            )->count(),

            'inactive_tables' => CanteenTable::where(
                'status',
                CanteenTable::STATUS_INACTIVE
            )->count(),

            'deleted_tables' => CanteenTable::onlyTrashed()
                ->count(),

            'total_capacity' => CanteenTable::where(
                'status',
                '!=',
                CanteenTable::STATUS_INACTIVE
            )->sum('capacity'),
        ];

        return $this->sendResponse(
            $summary,
            'Table summary retrieved successfully.'
        );
    }

    /**
     * Create a new canteen table.
     *
     * The model automatically generates the QR token.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make(
            $request->all(),
            [
                'table_number' => [
                    'required',
                    'string',
                    'max:50',
                    Rule::unique(
                        'canteen_tables',
                        'table_number'
                    ),
                ],

                'name' => [
                    'required',
                    'string',
                    'max:150',
                ],

                'location' => [
                    'nullable',
                    'string',
                    'max:150',
                ],

                'capacity' => [
                    'required',
                    'integer',
                    'min:1',
                    'max:100',
                ],

                'status' => [
                    'nullable',
                    Rule::in(
                        CanteenTable::statuses()
                    ),
                ],

                'description' => [
                    'nullable',
                    'string',
                    'max:2000',
                ],
            ],
            [
                'table_number.required' =>
                    'The table number is required.',

                'table_number.unique' =>
                    'This table number is already registered.',

                'name.required' =>
                    'The table name is required.',

                'capacity.required' =>
                    'The table capacity is required.',

                'capacity.min' =>
                    'The capacity must be at least one person.',

                'status.in' =>
                    'The selected table status is invalid.',
            ]
        );

        if ($validator->fails()) {
            return $this->sendError(
                'Validation failed.',
                $validator->errors(),
                422
            );
        }

        try {
            $canteenTable = DB::transaction(
                function () use ($request) {
                    $tableNumber = strtoupper(
                        trim(
                            (string) $request->input(
                                'table_number'
                            )
                        )
                    );

                    $canteenTable =
                        CanteenTable::create([
                            'table_number' => $tableNumber,

                            'name' => trim(
                                (string) $request->input(
                                    'name'
                                )
                            ),

                            'location' => $request->filled(
                                'location'
                            )
                                ? trim(
                                    (string) $request->input(
                                        'location'
                                    )
                                )
                                : null,

                            'capacity' => (int) $request->input(
                                'capacity'
                            ),

                            'status' => strtolower(
                                (string) $request->input(
                                    'status',
                                    CanteenTable::STATUS_AVAILABLE
                                )
                            ),

                            'description' => $request->filled(
                                'description'
                            )
                                ? trim(
                                    (string) $request->input(
                                        'description'
                                    )
                                )
                                : null,

                            /*
                             * qr_token is not supplied here.
                             * The model generates it automatically.
                             */
                            'created_by' => $request->user()?->id,
                            'updated_by' => $request->user()?->id,
                        ]);

                    return $canteenTable;
                }
            );

            $canteenTable->load([
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ]);

            return $this->sendResponse(
                $canteenTable,
                'Canteen table created and QR code generated successfully.'
            );
        } catch (Throwable $exception) {
            return $this->sendError(
                'Unable to create the canteen table.',
                [
                    'error' => $exception->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Display one canteen table.
     */
    public function show(
        CanteenTable $canteenTable
    ): JsonResponse {
        $canteenTable->load([
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);

        return $this->sendResponse(
            $canteenTable,
            'Canteen table retrieved successfully.'
        );
    }

    /**
     * Update an existing canteen table.
     */
    public function update(
        Request $request,
        CanteenTable $canteenTable
    ): JsonResponse {
        $validator = Validator::make(
            $request->all(),
            [
                'table_number' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:50',

                    Rule::unique(
                        'canteen_tables',
                        'table_number'
                    )->ignore($canteenTable->id),
                ],

                'name' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:150',
                ],

                'location' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:150',
                ],

                'capacity' => [
                    'sometimes',
                    'required',
                    'integer',
                    'min:1',
                    'max:100',
                ],

                'status' => [
                    'sometimes',
                    'required',
                    Rule::in(
                        CanteenTable::statuses()
                    ),
                ],

                'description' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:2000',
                ],
            ],
            [
                'table_number.unique' =>
                    'This table number is already registered.',

                'capacity.min' =>
                    'The capacity must be at least one person.',

                'status.in' =>
                    'The selected table status is invalid.',
            ]
        );

        if ($validator->fails()) {
            return $this->sendError(
                'Validation failed.',
                $validator->errors(),
                422
            );
        }

        try {
            DB::transaction(
                function () use (
                    $request,
                    $canteenTable
                ) {
                    $data = $request->only([
                        'table_number',
                        'name',
                        'location',
                        'capacity',
                        'status',
                        'description',
                    ]);

                    if (
                        array_key_exists(
                            'table_number',
                            $data
                        )
                    ) {
                        $data['table_number'] =
                            strtoupper(
                                trim(
                                    (string) $data[
                                        'table_number'
                                    ]
                                )
                            );
                    }

                    if (array_key_exists('name', $data)) {
                        $data['name'] = trim(
                            (string) $data['name']
                        );
                    }

                    if (
                        array_key_exists(
                            'location',
                            $data
                        )
                    ) {
                        $data['location'] =
                            filled($data['location'])
                                ? trim(
                                    (string) $data[
                                        'location'
                                    ]
                                )
                                : null;
                    }

                    if (
                        array_key_exists(
                            'description',
                            $data
                        )
                    ) {
                        $data['description'] =
                            filled($data['description'])
                                ? trim(
                                    (string) $data[
                                        'description'
                                    ]
                                )
                                : null;
                    }

                    if (
                        array_key_exists(
                            'capacity',
                            $data
                        )
                    ) {
                        $data['capacity'] =
                            (int) $data['capacity'];
                    }

                    if (
                        array_key_exists(
                            'status',
                            $data
                        )
                    ) {
                        $data['status'] =
                            strtolower(
                                (string) $data['status']
                            );
                    }

                    $data['updated_by'] =
                        $request->user()?->id;

                    /*
                     * qr_token is intentionally not changed
                     * during a normal table update.
                     */
                    $canteenTable->update($data);
                }
            );

            $canteenTable->refresh();

            $canteenTable->load([
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ]);

            return $this->sendResponse(
                $canteenTable,
                'Canteen table updated successfully.'
            );
        } catch (Throwable $exception) {
            return $this->sendError(
                'Unable to update the canteen table.',
                [
                    'error' => $exception->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Soft-delete a canteen table.
     */
    public function destroy(
        Request $request,
        CanteenTable $canteenTable
    ): JsonResponse {
        try {
            $canteenTable->update([
                'updated_by' => $request->user()?->id,
            ]);

            $canteenTable->delete();

            return $this->sendResponse(
                [
                    'id' => $canteenTable->id,
                    'table_number' =>
                        $canteenTable->table_number,
                ],
                'Canteen table deleted successfully.'
            );
        } catch (Throwable $exception) {
            return $this->sendError(
                'Unable to delete the canteen table.',
                [
                    'error' => $exception->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Restore a soft-deleted table.
     */
    public function restore(
        Request $request,
        int $id
    ): JsonResponse {
        $canteenTable = CanteenTable::withTrashed()
            ->find($id);

        if (!$canteenTable) {
            return $this->sendError(
                'Canteen table not found.',
                [],
                404
            );
        }

        if (!$canteenTable->trashed()) {
            return $this->sendError(
                'This table is not deleted.',
                [],
                422
            );
        }

        try {
            $canteenTable->restore();

            $canteenTable->update([
                'updated_by' => $request->user()?->id,
            ]);

            $canteenTable->load([
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ]);

            return $this->sendResponse(
                $canteenTable,
                'Canteen table restored successfully.'
            );
        } catch (Throwable $exception) {
            return $this->sendError(
                'Unable to restore the canteen table.',
                [
                    'error' => $exception->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Generate a new QR token.
     *
     * The old printed QR code will stop working.
     */
    public function regenerateQr(
        Request $request,
        CanteenTable $canteenTable
    ): JsonResponse {
        try {
            $canteenTable->update([
                'qr_token' =>
                    CanteenTable::generateUniqueQrToken(),

                'updated_by' =>
                    $request->user()?->id,
            ]);

            $canteenTable->refresh();

            return $this->sendResponse(
                $canteenTable,
                'A new table QR code was generated successfully.'
            );
        } catch (Throwable $exception) {
            return $this->sendError(
                'Unable to regenerate the table QR code.',
                [
                    'error' => $exception->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Public endpoint opened when a customer scans
     * the table QR code.
     *
     * This endpoint does not require authentication.
     */
    public function publicShow(
        string $qrToken
    ): JsonResponse {
        $canteenTable = CanteenTable::query()
            ->where('qr_token', $qrToken)
            ->first();

        if (!$canteenTable) {
            return $this->sendError(
                'The table QR code is invalid or no longer active.',
                [],
                404
            );
        }

        /*
         * Only return public table information.
         * Do not expose created_by or updated_by.
         */
        $publicData = [
            'id' => $canteenTable->id,

            'table_number' =>
                $canteenTable->table_number,

            'name' => $canteenTable->name,

            'location' =>
                $canteenTable->location,

            'capacity' =>
                $canteenTable->capacity,

            'status' =>
                $canteenTable->status,

            'description' =>
                $canteenTable->description,

            'qr_token' =>
                $canteenTable->qr_token,

            'qr_url' =>
                $canteenTable->qr_url,

            'updated_at' =>
                $canteenTable->updated_at,
        ];

        return $this->sendResponse(
            $publicData,
            'Table information retrieved successfully.'
        );
    }
}