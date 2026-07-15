<?php

namespace App\Http\Controllers\API;

use App\Models\FoodCategory;
use App\Models\FoodItem;
use App\Models\InventoryStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FoodItemController extends BaseController
{
    /**
     * Display food items.
     *
     * Students only receive items that are active, manually available,
     * inside an active category, and have available stock.
     */
    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();
        $canManage = $authUser?->canManageInventory() ?? false;

        $query = FoodItem::query()
            ->with([
                'category:id,name,slug,status',
                'inventoryStock:id,food_item_id,quantity,reserved_quantity,low_stock_quantity,status,location',
                'createdBy:id,name,email,phone,role',
                'updatedBy:id,name,email,phone,role',
            ])
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($canManage) {
            if (
                $request->boolean('with_trashed') ||
                $request->boolean('include_deleted')
            ) {
                $query->withTrashed();
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('is_available')) {
                $query->where(
                    'is_available',
                    $request->boolean('is_available')
                );
            }
        } else {
            $query
                ->where('status', FoodItem::STATUS_ACTIVE)
                ->where('is_available', true)
                ->whereHas('category', function ($categoryQuery) {
                    $categoryQuery->where(
                        'status',
                        FoodCategory::STATUS_ACTIVE
                    );
                })
                ->whereHas('inventoryStock', function ($stockQuery) {
                    $stockQuery
                        ->where('status', InventoryStock::STATUS_ACTIVE)
                        ->whereRaw(
                            '(quantity - COALESCE(reserved_quantity, 0)) > 0'
                        );
                });
        }

        if ($request->filled('food_category_id')) {
            $query->where(
                'food_category_id',
                $request->food_category_id
            );
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('sku', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%');
            });
        }

        $perPage = min(max((int) $request->get('per_page', 20), 1), 200);
        $foodItems = $query->paginate($perPage);

        $foodItems->getCollection()->transform(
            fn (FoodItem $foodItem) => $this->prepareFoodItemResponse($foodItem)
        );

        return $this->sendResponse(
            $foodItems,
            'Food items retrieved successfully.'
        );
    }

    /**
     * Store a new food item.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can create food items.'
            );
        }

        $validator = Validator::make($request->all(), [
            'food_category_id' => [
                'required',
                'integer',
                Rule::exists('food_categories', 'id')
                    ->whereNull('deleted_at'),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('food_items', 'name'),
            ],
            'sku' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('food_items', 'sku'),
            ],
            'description' => ['nullable', 'string'],
            'image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
            'price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'low_stock_quantity' => ['nullable', 'integer', 'min:0'],
            'status' => [
                'nullable',
                Rule::in([
                    FoodItem::STATUS_ACTIVE,
                    FoodItem::STATUS_INACTIVE,
                ]),
            ],
            'is_available' => ['nullable', 'boolean'],
            'preparation_time_minutes' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        $imagePath = null;

        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store(
                'food-items',
                'public'
            );
        }

        $name = trim((string) $request->name);

        $foodItem = FoodItem::create([
            'food_category_id' => (int) $request->food_category_id,
            'name' => $name,
            'slug' => $this->generateUniqueSlug($name),
            'sku' => $request->filled('sku')
                ? trim((string) $request->sku)
                : null,
            'description' => $request->description,
            'image' => $imagePath,
            'price' => $request->price,
            'cost_price' => $request->cost_price,
            'unit' => $request->unit ?? 'piece',
            'low_stock_quantity' => $request->low_stock_quantity ?? 5,
            'status' => $request->status ?? FoodItem::STATUS_ACTIVE,
            'is_available' => $request->has('is_available')
                ? $request->boolean('is_available')
                : true,
            'preparation_time_minutes' => $request->preparation_time_minutes,
            'sort_order' => $request->sort_order ?? 0,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $foodItem->load($this->defaultRelations());

        return $this->sendCreated(
            $this->prepareFoodItemResponse($foodItem),
            'Food item created successfully.'
        );
    }

    /**
     * Display one food item.
     */
    public function show(
        Request $request,
        FoodItem $foodItem
    ): JsonResponse {
        $foodItem->load($this->defaultRelations());

        if (!$request->user()->canManageInventory()) {
            $availableQuantity = $this->availableQuantity($foodItem);

            if (
                !$foodItem->isActive() ||
                !$foodItem->isAvailable() ||
                !$foodItem->category ||
                $foodItem->category->status !== FoodCategory::STATUS_ACTIVE ||
                !$foodItem->inventoryStock ||
                $foodItem->inventoryStock->status !== InventoryStock::STATUS_ACTIVE ||
                $availableQuantity <= 0
            ) {
                return $this->sendNotFound('Food item not found.');
            }
        }

        return $this->sendResponse(
            $this->prepareFoodItemResponse($foodItem),
            'Food item retrieved successfully.'
        );
    }

    /**
     * Update a food item.
     *
     * Supports PUT and PATCH without clearing omitted fields.
     */
    public function update(
        Request $request,
        FoodItem $foodItem
    ): JsonResponse {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can update food items.'
            );
        }

        $validator = Validator::make($request->all(), [
            'food_category_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('food_categories', 'id')
                    ->whereNull('deleted_at'),
            ],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('food_items', 'name')
                    ->ignore($foodItem->id),
            ],
            'sku' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                Rule::unique('food_items', 'sku')
                    ->ignore($foodItem->id),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'image' => [
                'sometimes',
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
            'remove_image' => ['sometimes', 'boolean'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:50'],
            'low_stock_quantity' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status' => [
                'sometimes',
                'required',
                Rule::in([
                    FoodItem::STATUS_ACTIVE,
                    FoodItem::STATUS_INACTIVE,
                ]),
            ],
            'is_available' => ['sometimes', 'required', 'boolean'],
            'preparation_time_minutes' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
            ],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        $data = [];

        if ($request->has('food_category_id')) {
            $data['food_category_id'] = (int) $request->food_category_id;
        }

        if ($request->has('name')) {
            $name = trim((string) $request->name);
            $data['name'] = $name;
            $data['slug'] = $this->generateUniqueSlug(
                $name,
                $foodItem->id
            );
        }

        if ($request->has('sku')) {
            $data['sku'] = $request->filled('sku')
                ? trim((string) $request->sku)
                : null;
        }

        foreach ([
            'description',
            'price',
            'cost_price',
            'unit',
            'low_stock_quantity',
            'status',
            'preparation_time_minutes',
            'sort_order',
        ] as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        if ($request->has('is_available')) {
            $data['is_available'] = $request->boolean('is_available');
        }

        if ($request->boolean('remove_image')) {
            $this->deleteStoredImage($foodItem->image);
            $data['image'] = null;
        } elseif ($request->hasFile('image')) {
            $newImagePath = $request->file('image')->store(
                'food-items',
                'public'
            );

            $this->deleteStoredImage($foodItem->image);
            $data['image'] = $newImagePath;
        }

        $data['updated_by'] = $request->user()->id;

        $foodItem->update($data);
        $foodItem->load($this->defaultRelations());

        return $this->sendResponse(
            $this->prepareFoodItemResponse($foodItem),
            'Food item updated successfully.'
        );
    }

    /**
     * Soft-delete a food item.
     */
    public function destroy(
        Request $request,
        FoodItem $foodItem
    ): JsonResponse {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can delete food items.'
            );
        }

        if (
            $foodItem->inventoryStock &&
            (
                (int) $foodItem->inventoryStock->quantity > 0 ||
                (int) $foodItem->inventoryStock->reserved_quantity > 0
            )
        ) {
            return $this->sendError(
                'This food item still has stock. Reduce the stock to zero before deleting it.',
                [],
                400
            );
        }

        $foodItem->delete();

        return $this->sendResponse([], 'Food item deleted successfully.');
    }

    /**
     * Restore a deleted food item.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can restore food items.'
            );
        }

        $foodItem = FoodItem::onlyTrashed()->find($id);

        if (!$foodItem) {
            return $this->sendNotFound('Deleted food item not found.');
        }

        $foodItem->restore();
        $foodItem->load($this->defaultRelations());

        return $this->sendResponse(
            $this->prepareFoodItemResponse($foodItem),
            'Food item restored successfully.'
        );
    }

    /**
     * Change manual food-item availability.
     */
    public function updateAvailability(
        Request $request,
        FoodItem $foodItem
    ): JsonResponse {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can change food availability.'
            );
        }

        $validator = Validator::make($request->all(), [
            'is_available' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        $foodItem->update([
            'is_available' => $request->boolean('is_available'),
            'updated_by' => $request->user()->id,
        ]);

        $foodItem->load($this->defaultRelations());

        return $this->sendResponse(
            $this->prepareFoodItemResponse($foodItem),
            'Food item availability updated successfully.'
        );
    }

    /**
     * Default API relations.
     */
    private function defaultRelations(): array
    {
        return [
            'category:id,name,slug,status',
            'inventoryStock:id,food_item_id,quantity,reserved_quantity,low_stock_quantity,status,location',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ];
    }

    /**
     * Add image and stock information to the response.
     */
    private function prepareFoodItemResponse(
        FoodItem $foodItem
    ): FoodItem {
        $imageUrl = null;

        if ($foodItem->image) {
            if (Str::startsWith($foodItem->image, ['http://', 'https://'])) {
                $imageUrl = $foodItem->image;
            } else {
                $imageUrl = url(
                    Storage::disk('public')->url($foodItem->image)
                );
            }
        }

        $foodItem->setAttribute('image_url', $imageUrl);
        $foodItem->setAttribute(
            'available_quantity',
            $this->availableQuantity($foodItem)
        );

        return $foodItem;
    }

    /**
     * Calculate sellable stock after reservations.
     */
    private function availableQuantity(FoodItem $foodItem): int
    {
        if (!$foodItem->relationLoaded('inventoryStock')) {
            $foodItem->load(
                'inventoryStock:id,food_item_id,quantity,reserved_quantity,low_stock_quantity,status,location'
            );
        }

        if (!$foodItem->inventoryStock) {
            return 0;
        }

        return max(
            0,
            (int) $foodItem->inventoryStock->quantity -
            (int) $foodItem->inventoryStock->reserved_quantity
        );
    }

    /**
     * Delete an old locally stored food image.
     */
    private function deleteStoredImage(?string $imagePath): void
    {
        if (
            !$imagePath ||
            Str::startsWith($imagePath, ['http://', 'https://'])
        ) {
            return;
        }

        if (Storage::disk('public')->exists($imagePath)) {
            Storage::disk('public')->delete($imagePath);
        }
    }

    /**
     * Generate a unique slug, including soft-deleted records.
     */
    private function generateUniqueSlug(
        string $name,
        ?int $ignoreId = null
    ): string {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug !== '' ? $baseSlug : 'food-item';
        $originalSlug = $slug;
        $counter = 1;

        while (
            FoodItem::withTrashed()
                ->where('slug', $slug)
                ->when($ignoreId, function ($query) use ($ignoreId) {
                    $query->where('id', '!=', $ignoreId);
                })
                ->exists()
        ) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}