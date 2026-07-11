<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\ActivityLog;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SystemSettingController extends BaseController
{
    /**
     * Display system settings.
     */
    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();

        $query = SystemSetting::query()
            ->with([
                'createdBy:id,name,email,phone,role',
                'updatedBy:id,name,email,phone,role',
            ])
            ->orderBy('setting_group')
            ->orderBy('sort_order')
            ->orderBy('setting_key');

        if (!$this->canManageSettings($authUser)) {
            $query->where('is_public', true)
                ->where('status', SystemSetting::STATUS_ACTIVE);
        }

        if ($request->filled('setting_group')) {
            $query->where('setting_group', $request->setting_group);
        }

        if ($request->filled('value_type')) {
            $query->where('value_type', $request->value_type);
        }

        if ($request->filled('status') && $this->canManageSettings($authUser)) {
            $query->where('status', $request->status);
        }

        if ($request->filled('is_public') && $this->canManageSettings($authUser)) {
            $query->where('is_public', $request->boolean('is_public'));
        }

        if ($request->filled('is_editable') && $this->canManageSettings($authUser)) {
            $query->where('is_editable', $request->boolean('is_editable'));
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('setting_key', 'like', '%' . $request->search . '%')
                    ->orWhere('label', 'like', '%' . $request->search . '%')
                    ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }

        $perPage = $request->get('per_page', 50);

        $settings = $query->paginate($perPage);

        $settings->getCollection()->transform(function ($setting) {
            $setting->typed_value = $setting->typedValue();

            return $setting;
        });

        return $this->sendResponse($settings, 'System settings retrieved successfully.');
    }

    /**
     * Public settings for mobile/frontend.
     */
    public function publicSettings(): JsonResponse
    {
        $settings = SystemSetting::query()
            ->where('is_public', true)
            ->where('status', SystemSetting::STATUS_ACTIVE)
            ->orderBy('setting_group')
            ->orderBy('sort_order')
            ->orderBy('setting_key')
            ->get()
            ->groupBy('setting_group')
            ->map(function ($groupSettings) {
                return $groupSettings->mapWithKeys(function ($setting) {
                    return [
                        $setting->setting_key => [
                            'key' => $setting->setting_key,
                            'value' => $setting->typedValue(),
                            'value_type' => $setting->value_type,
                            'label' => $setting->label,
                            'description' => $setting->description,
                        ],
                    ];
                });
            });

        return $this->sendResponse($settings, 'Public system settings retrieved successfully.');
    }

    /**
     * Store system setting.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$this->canManageSettings($request->user())) {
            return $this->sendForbidden('Only admin or staff can create system settings.');
        }

        $validator = Validator::make($request->all(), [
            'setting_key' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9_.-]+$/',
                'unique:system_settings,setting_key',
            ],
            'setting_value' => 'nullable',
            'value_type' => 'nullable|in:string,integer,decimal,boolean,json',
            'setting_group' => 'nullable|string|max:100',
            'label' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_public' => 'nullable|boolean',
            'is_editable' => 'nullable|boolean',
            'status' => 'nullable|in:active,inactive',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $valueType = $this->guessValueType(
            $request->input('setting_value'),
            $request->value_type
        );

        $setting = SystemSetting::create([
            'setting_key' => $request->setting_key,
            'setting_value' => SystemSetting::normalizeValue(
                $request->input('setting_value'),
                $valueType
            ),
            'value_type' => $valueType,
            'setting_group' => $request->setting_group ?? SystemSetting::GROUP_GENERAL,
            'label' => $request->label,
            'description' => $request->description,
            'is_public' => $request->boolean('is_public', false),
            'is_editable' => $request->boolean('is_editable', true),
            'status' => $request->status ?? SystemSetting::STATUS_ACTIVE,
            'sort_order' => $request->sort_order ?? 0,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        ActivityLog::record([
            'module' => ActivityLog::MODULE_SYSTEM,
            'action' => 'create_setting',
            'description' => 'System setting created successfully.',
            'subject' => $setting,
            'new_values' => $setting->toArray(),
        ], $request);

        $setting->typed_value = $setting->typedValue();

        $setting->load([
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendCreated($setting, 'System setting created successfully.');
    }

    /**
     * Display one system setting.
     */
    public function show(Request $request, SystemSetting $systemSetting): JsonResponse
    {
        if (
            !$this->canManageSettings($request->user())
            && (!$systemSetting->isPublic() || !$systemSetting->isActive())
        ) {
            return $this->sendForbidden('You are not allowed to view this system setting.');
        }

        $systemSetting->load([
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        $systemSetting->typed_value = $systemSetting->typedValue();

        return $this->sendResponse($systemSetting, 'System setting retrieved successfully.');
    }

    /**
     * Get setting by key.
     */
    public function getByKey(Request $request, string $settingKey): JsonResponse
    {
        $setting = SystemSetting::where('setting_key', $settingKey)->first();

        if (!$setting) {
            return $this->sendNotFound('System setting not found.');
        }

        if (
            !$this->canManageSettings($request->user())
            && (!$setting->isPublic() || !$setting->isActive())
        ) {
            return $this->sendForbidden('You are not allowed to view this system setting.');
        }

        $setting->typed_value = $setting->typedValue();

        return $this->sendResponse($setting, 'System setting retrieved successfully.');
    }

    /**
     * Update system setting.
     */
    public function update(Request $request, SystemSetting $systemSetting): JsonResponse
    {
        if (!$this->canManageSettings($request->user())) {
            return $this->sendForbidden('Only admin or staff can update system settings.');
        }

        if (!$systemSetting->isEditable()) {
            return $this->sendError('This system setting is not editable.', [], 400);
        }

        $validator = Validator::make($request->all(), [
            'setting_key' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-z0-9_.-]+$/',
                'unique:system_settings,setting_key,' . $systemSetting->id,
            ],
            'setting_value' => 'nullable',
            'value_type' => 'nullable|in:string,integer,decimal,boolean,json',
            'setting_group' => 'nullable|string|max:100',
            'label' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_public' => 'nullable|boolean',
            'is_editable' => 'nullable|boolean',
            'status' => 'nullable|in:active,inactive',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $oldValues = $systemSetting->toArray();

        $valueType = $request->value_type ?? $systemSetting->value_type;

        $rawValue = $request->has('setting_value')
            ? $request->input('setting_value')
            : $systemSetting->typedValue();

        $systemSetting->update([
            'setting_key' => $request->setting_key ?? $systemSetting->setting_key,
            'setting_value' => SystemSetting::normalizeValue($rawValue, $valueType),
            'value_type' => $valueType,
            'setting_group' => $request->setting_group ?? $systemSetting->setting_group,
            'label' => $request->has('label') ? $request->label : $systemSetting->label,
            'description' => $request->has('description') ? $request->description : $systemSetting->description,
            'is_public' => $request->has('is_public')
                ? $request->boolean('is_public')
                : $systemSetting->is_public,
            'is_editable' => $request->has('is_editable')
                ? $request->boolean('is_editable')
                : $systemSetting->is_editable,
            'status' => $request->status ?? $systemSetting->status,
            'sort_order' => $request->sort_order ?? $systemSetting->sort_order,
            'updated_by' => $request->user()->id,
        ]);

        ActivityLog::record([
            'module' => ActivityLog::MODULE_SYSTEM,
            'action' => 'update_setting',
            'description' => 'System setting updated successfully.',
            'subject' => $systemSetting,
            'old_values' => $oldValues,
            'new_values' => $systemSetting->fresh()->toArray(),
        ], $request);

        $systemSetting->load([
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        $systemSetting->typed_value = $systemSetting->typedValue();

        return $this->sendResponse($systemSetting, 'System setting updated successfully.');
    }

    /**
     * Update setting by key.
     */
    public function updateByKey(Request $request, string $settingKey): JsonResponse
    {
        $setting = SystemSetting::where('setting_key', $settingKey)->first();

        if (!$setting) {
            return $this->sendNotFound('System setting not found.');
        }

        return $this->update($request, $setting);
    }

    /**
     * Bulk update settings.
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        if (!$this->canManageSettings($request->user())) {
            return $this->sendForbidden('Only admin or staff can bulk update system settings.');
        }

        $validator = Validator::make($request->all(), [
            'settings' => 'required|array|min:1',
            'settings.*.setting_key' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9_.-]+$/',
            ],
            'settings.*.setting_value' => 'nullable',
            'settings.*.value_type' => 'nullable|in:string,integer,decimal,boolean,json',
            'settings.*.setting_group' => 'nullable|string|max:100',
            'settings.*.label' => 'nullable|string|max:255',
            'settings.*.description' => 'nullable|string',
            'settings.*.is_public' => 'nullable|boolean',
            'settings.*.is_editable' => 'nullable|boolean',
            'settings.*.status' => 'nullable|in:active,inactive',
            'settings.*.sort_order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $result = DB::transaction(function () use ($request) {
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $settings = [];

            foreach ($request->settings as $item) {
                $setting = SystemSetting::where('setting_key', $item['setting_key'])->first();

                if ($setting && !$setting->isEditable()) {
                    $skipped++;
                    continue;
                }

                $rawValue = array_key_exists('setting_value', $item)
                    ? $item['setting_value']
                    : ($setting ? $setting->typedValue() : null);

                $valueType = $this->guessValueType(
                    $rawValue,
                    $item['value_type'] ?? $setting?->value_type
                );

                if ($setting) {
                    $setting->update([
                        'setting_value' => SystemSetting::normalizeValue($rawValue, $valueType),
                        'value_type' => $valueType,
                        'setting_group' => $item['setting_group'] ?? $setting->setting_group,
                        'label' => array_key_exists('label', $item) ? $item['label'] : $setting->label,
                        'description' => array_key_exists('description', $item) ? $item['description'] : $setting->description,
                        'is_public' => array_key_exists('is_public', $item)
                            ? (bool) $item['is_public']
                            : $setting->is_public,
                        'is_editable' => array_key_exists('is_editable', $item)
                            ? (bool) $item['is_editable']
                            : $setting->is_editable,
                        'status' => $item['status'] ?? $setting->status,
                        'sort_order' => $item['sort_order'] ?? $setting->sort_order,
                        'updated_by' => $request->user()->id,
                    ]);

                    $updated++;
                } else {
                    $setting = SystemSetting::create([
                        'setting_key' => $item['setting_key'],
                        'setting_value' => SystemSetting::normalizeValue($rawValue, $valueType),
                        'value_type' => $valueType,
                        'setting_group' => $item['setting_group'] ?? SystemSetting::GROUP_GENERAL,
                        'label' => $item['label'] ?? null,
                        'description' => $item['description'] ?? null,
                        'is_public' => $item['is_public'] ?? false,
                        'is_editable' => $item['is_editable'] ?? true,
                        'status' => $item['status'] ?? SystemSetting::STATUS_ACTIVE,
                        'sort_order' => $item['sort_order'] ?? 0,
                        'created_by' => $request->user()->id,
                        'updated_by' => $request->user()->id,
                    ]);

                    $created++;
                }

                $setting->typed_value = $setting->typedValue();
                $settings[] = $setting;
            }

            ActivityLog::record([
                'module' => ActivityLog::MODULE_SYSTEM,
                'action' => 'bulk_update_settings',
                'description' => 'System settings bulk updated.',
                'metadata' => [
                    'created' => $created,
                    'updated' => $updated,
                    'skipped' => $skipped,
                ],
            ], $request);

            return [
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'settings' => $settings,
            ];
        });

        return $this->sendResponse($result, 'System settings bulk update completed successfully.');
    }

    /**
     * Seed default system settings.
     */
    public function seedDefaults(Request $request): JsonResponse
    {
        if (!$this->canManageSettings($request->user())) {
            return $this->sendForbidden('Only admin or staff can seed default system settings.');
        }

        $created = 0;
        $skipped = 0;

        foreach ($this->defaultSettings() as $default) {
            $setting = SystemSetting::firstOrCreate(
                ['setting_key' => $default['setting_key']],
                [
                    'setting_value' => SystemSetting::normalizeValue(
                        $default['setting_value'],
                        $default['value_type']
                    ),
                    'value_type' => $default['value_type'],
                    'setting_group' => $default['setting_group'],
                    'label' => $default['label'],
                    'description' => $default['description'],
                    'is_public' => $default['is_public'],
                    'is_editable' => $default['is_editable'],
                    'status' => SystemSetting::STATUS_ACTIVE,
                    'sort_order' => $default['sort_order'],
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]
            );

            if ($setting->wasRecentlyCreated) {
                $created++;
            } else {
                $skipped++;
            }
        }

        ActivityLog::record([
            'module' => ActivityLog::MODULE_SYSTEM,
            'action' => 'seed_default_settings',
            'description' => 'Default system settings seeded.',
            'metadata' => [
                'created' => $created,
                'skipped' => $skipped,
            ],
        ], $request);

        return $this->sendResponse([
            'created' => $created,
            'skipped' => $skipped,
        ], 'Default system settings seeded successfully.');
    }

    /**
     * Delete system setting.
     */
    public function destroy(Request $request, SystemSetting $systemSetting): JsonResponse
    {
        if (!$this->canManageSettings($request->user())) {
            return $this->sendForbidden('Only admin or staff can delete system settings.');
        }

        if (!$systemSetting->isEditable()) {
            return $this->sendError('This system setting is protected and cannot be deleted.', [], 400);
        }

        $oldValues = $systemSetting->toArray();

        $systemSetting->delete();

        ActivityLog::record([
            'module' => ActivityLog::MODULE_SYSTEM,
            'action' => 'delete_setting',
            'description' => 'System setting deleted successfully.',
            'subject_type' => SystemSetting::class,
            'subject_id' => $systemSetting->id,
            'old_values' => $oldValues,
        ], $request);

        return $this->sendResponse([], 'System setting deleted successfully.');
    }

    /**
     * Restore deleted setting.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$this->canManageSettings($request->user())) {
            return $this->sendForbidden('Only admin or staff can restore system settings.');
        }

        $setting = SystemSetting::onlyTrashed()->find($id);

        if (!$setting) {
            return $this->sendNotFound('Deleted system setting not found.');
        }

        $setting->restore();

        ActivityLog::record([
            'module' => ActivityLog::MODULE_SYSTEM,
            'action' => 'restore_setting',
            'description' => 'System setting restored successfully.',
            'subject' => $setting,
        ], $request);

        $setting->load([
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        $setting->typed_value = $setting->typedValue();

        return $this->sendResponse($setting, 'System setting restored successfully.');
    }

    /**
     * System setting summary.
     */
    public function summary(Request $request): JsonResponse
    {
        if (!$this->canManageSettings($request->user())) {
            return $this->sendForbidden('Only admin or staff can view system setting summary.');
        }

        $query = SystemSetting::query();

        $totalSettings = (clone $query)->count();

        $activeSettings = (clone $query)
            ->where('status', SystemSetting::STATUS_ACTIVE)
            ->count();

        $inactiveSettings = (clone $query)
            ->where('status', SystemSetting::STATUS_INACTIVE)
            ->count();

        $publicSettings = (clone $query)
            ->where('is_public', true)
            ->count();

        $privateSettings = (clone $query)
            ->where('is_public', false)
            ->count();

        $editableSettings = (clone $query)
            ->where('is_editable', true)
            ->count();

        $protectedSettings = (clone $query)
            ->where('is_editable', false)
            ->count();

        $byGroup = (clone $query)
            ->select(
                'setting_group',
                DB::raw('COUNT(*) as total_records'),
                DB::raw('SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active_records'),
                DB::raw('SUM(CASE WHEN is_public = 1 THEN 1 ELSE 0 END) as public_records')
            )
            ->groupBy('setting_group')
            ->orderBy('setting_group')
            ->get();

        $byType = (clone $query)
            ->select('value_type', DB::raw('COUNT(*) as total_records'))
            ->groupBy('value_type')
            ->orderBy('value_type')
            ->get();

        return $this->sendResponse([
            'total_settings' => $totalSettings,
            'active_settings' => $activeSettings,
            'inactive_settings' => $inactiveSettings,
            'public_settings' => $publicSettings,
            'private_settings' => $privateSettings,
            'editable_settings' => $editableSettings,
            'protected_settings' => $protectedSettings,
            'by_group' => $byGroup,
            'by_type' => $byType,
        ], 'System setting summary retrieved successfully.');
    }

    /**
     * Guess value type.
     */
    private function guessValueType(mixed $value, ?string $requestedType = null): string
    {
        if ($requestedType) {
            return $requestedType;
        }

        if (is_bool($value)) {
            return SystemSetting::TYPE_BOOLEAN;
        }

        if (is_int($value)) {
            return SystemSetting::TYPE_INTEGER;
        }

        if (is_float($value)) {
            return SystemSetting::TYPE_DECIMAL;
        }

        if (is_array($value)) {
            return SystemSetting::TYPE_JSON;
        }

        return SystemSetting::TYPE_STRING;
    }

    /**
     * Check permission.
     */
    private function canManageSettings($user): bool
    {
        if (!$user) {
            return false;
        }

        if (method_exists($user, 'canManageInventory')) {
            return $user->canManageInventory();
        }

        return in_array($user->role, ['admin', 'staff'], true);
    }

    /**
     * Default system settings.
     */
    private function defaultSettings(): array
    {
        return [
            [
                'setting_key' => 'app.name',
                'setting_value' => 'Smart Canteen',
                'value_type' => SystemSetting::TYPE_STRING,
                'setting_group' => SystemSetting::GROUP_GENERAL,
                'label' => 'Application Name',
                'description' => 'Name shown in mobile app, dashboard, and reports.',
                'is_public' => true,
                'is_editable' => true,
                'sort_order' => 1,
            ],
            [
                'setting_key' => 'app.timezone',
                'setting_value' => 'Africa/Kigali',
                'value_type' => SystemSetting::TYPE_STRING,
                'setting_group' => SystemSetting::GROUP_GENERAL,
                'label' => 'Timezone',
                'description' => 'Default system timezone.',
                'is_public' => true,
                'is_editable' => true,
                'sort_order' => 2,
            ],
            [
                'setting_key' => 'app.currency',
                'setting_value' => 'RWF',
                'value_type' => SystemSetting::TYPE_STRING,
                'setting_group' => SystemSetting::GROUP_LOCALIZATION,
                'label' => 'Currency',
                'description' => 'Default currency code.',
                'is_public' => true,
                'is_editable' => true,
                'sort_order' => 3,
            ],
            [
                'setting_key' => 'app.currency_symbol',
                'setting_value' => 'FRw',
                'value_type' => SystemSetting::TYPE_STRING,
                'setting_group' => SystemSetting::GROUP_LOCALIZATION,
                'label' => 'Currency Symbol',
                'description' => 'Currency symbol displayed on frontend.',
                'is_public' => true,
                'is_editable' => true,
                'sort_order' => 4,
            ],
            [
                'setting_key' => 'wallet.minimum_topup',
                'setting_value' => 500,
                'value_type' => SystemSetting::TYPE_INTEGER,
                'setting_group' => SystemSetting::GROUP_WALLET,
                'label' => 'Minimum Wallet Top-up',
                'description' => 'Minimum amount allowed for wallet top-up.',
                'is_public' => true,
                'is_editable' => true,
                'sort_order' => 10,
            ],
            [
                'setting_key' => 'wallet.maximum_topup',
                'setting_value' => 100000,
                'value_type' => SystemSetting::TYPE_INTEGER,
                'setting_group' => SystemSetting::GROUP_WALLET,
                'label' => 'Maximum Wallet Top-up',
                'description' => 'Maximum amount allowed for wallet top-up.',
                'is_public' => true,
                'is_editable' => true,
                'sort_order' => 11,
            ],
            [
                'setting_key' => 'wallet.allow_negative_balance',
                'setting_value' => false,
                'value_type' => SystemSetting::TYPE_BOOLEAN,
                'setting_group' => SystemSetting::GROUP_WALLET,
                'label' => 'Allow Negative Wallet Balance',
                'description' => 'Whether users can place orders with insufficient wallet balance.',
                'is_public' => false,
                'is_editable' => true,
                'sort_order' => 12,
            ],
            [
                'setting_key' => 'qr.expiry_hours',
                'setting_value' => 24,
                'value_type' => SystemSetting::TYPE_INTEGER,
                'setting_group' => SystemSetting::GROUP_QR,
                'label' => 'QR Expiry Hours',
                'description' => 'Number of hours before order QR code expires.',
                'is_public' => false,
                'is_editable' => true,
                'sort_order' => 20,
            ],
            [
                'setting_key' => 'qr.allow_regenerate',
                'setting_value' => true,
                'value_type' => SystemSetting::TYPE_BOOLEAN,
                'setting_group' => SystemSetting::GROUP_QR,
                'label' => 'Allow QR Regeneration',
                'description' => 'Whether staff/admin can regenerate QR code.',
                'is_public' => false,
                'is_editable' => true,
                'sort_order' => 21,
            ],
            [
                'setting_key' => 'inventory.default_low_stock_quantity',
                'setting_value' => 5,
                'value_type' => SystemSetting::TYPE_INTEGER,
                'setting_group' => SystemSetting::GROUP_INVENTORY,
                'label' => 'Default Low Stock Quantity',
                'description' => 'Default threshold for low-stock alerts.',
                'is_public' => false,
                'is_editable' => true,
                'sort_order' => 30,
            ],
            [
                'setting_key' => 'inventory.auto_generate_low_stock_alerts',
                'setting_value' => true,
                'value_type' => SystemSetting::TYPE_BOOLEAN,
                'setting_group' => SystemSetting::GROUP_INVENTORY,
                'label' => 'Auto Generate Low Stock Alerts',
                'description' => 'Whether system should auto-generate low-stock alerts.',
                'is_public' => false,
                'is_editable' => true,
                'sort_order' => 31,
            ],
            [
                'setting_key' => 'orders.auto_confirm_after_payment',
                'setting_value' => true,
                'value_type' => SystemSetting::TYPE_BOOLEAN,
                'setting_group' => SystemSetting::GROUP_ORDERS,
                'label' => 'Auto Confirm Orders After Payment',
                'description' => 'Whether paid orders are automatically confirmed.',
                'is_public' => false,
                'is_editable' => true,
                'sort_order' => 40,
            ],
            [
                'setting_key' => 'orders.allow_paid_order_cancellation',
                'setting_value' => true,
                'value_type' => SystemSetting::TYPE_BOOLEAN,
                'setting_group' => SystemSetting::GROUP_ORDERS,
                'label' => 'Allow Paid Order Cancellation',
                'description' => 'Whether paid orders can be cancelled and refunded before pickup.',
                'is_public' => false,
                'is_editable' => true,
                'sort_order' => 41,
            ],
            [
                'setting_key' => 'reports.default_status',
                'setting_value' => 'draft',
                'value_type' => SystemSetting::TYPE_STRING,
                'setting_group' => SystemSetting::GROUP_REPORTS,
                'label' => 'Default Report Status',
                'description' => 'Default status when generating reports.',
                'is_public' => false,
                'is_editable' => true,
                'sort_order' => 50,
            ],
            [
                'setting_key' => 'reports.auto_finalize_daily',
                'setting_value' => false,
                'value_type' => SystemSetting::TYPE_BOOLEAN,
                'setting_group' => SystemSetting::GROUP_REPORTS,
                'label' => 'Auto Finalize Daily Reports',
                'description' => 'Whether daily reports should be auto-finalized.',
                'is_public' => false,
                'is_editable' => true,
                'sort_order' => 51,
            ],
            [
                'setting_key' => 'notifications.low_stock_enabled',
                'setting_value' => true,
                'value_type' => SystemSetting::TYPE_BOOLEAN,
                'setting_group' => SystemSetting::GROUP_NOTIFICATIONS,
                'label' => 'Low Stock Notifications Enabled',
                'description' => 'Whether low-stock notifications are enabled.',
                'is_public' => false,
                'is_editable' => true,
                'sort_order' => 60,
            ],
            [
                'setting_key' => 'notifications.email_enabled',
                'setting_value' => false,
                'value_type' => SystemSetting::TYPE_BOOLEAN,
                'setting_group' => SystemSetting::GROUP_NOTIFICATIONS,
                'label' => 'Email Notifications Enabled',
                'description' => 'Whether email notifications are enabled.',
                'is_public' => false,
                'is_editable' => true,
                'sort_order' => 61,
            ],
            [
                'setting_key' => 'system.maintenance_mode',
                'setting_value' => false,
                'value_type' => SystemSetting::TYPE_BOOLEAN,
                'setting_group' => SystemSetting::GROUP_SYSTEM,
                'label' => 'Maintenance Mode',
                'description' => 'Whether the system is under maintenance.',
                'is_public' => true,
                'is_editable' => true,
                'sort_order' => 70,
            ],
        ];
    }
}