<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\FoodItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FoodItemController extends BaseController
{
    /**
     * Display all food items.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FoodItem::query()
            ->with([
                'category:id,name,slug,status',
                'createdBy:id,name,email,phone,role',
                'updatedBy:id,name,email,phone,role',
            ])
            ->orderBy('sort_order')
            ->orderBy('name');

        /*
         * Include soft-deleted records when requested.
         *
         * Examples:
         * ?with_trashed=1
         * ?include_deleted=1
         */
        if (
            $request->boolean('with_trashed') ||
            $request->boolean('include_deleted')
        ) {
            $query->withTrashed();
        }

        if ($request->filled('food_category_id')) {
            $query->where(
                'food_category_id',
                $request->food_category_id
            );
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

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('sku', 'like', '%' . $search . '%')
                    ->orWhere(
                        'description',
                        'like',
                        '%' . $search . '%'
                    );
            });
        }

        $perPage = min(
            max((int) $request->get('per_page', 20), 1),
            200
        );

        $foodItems = $query->paginate($perPage);

        $foodItems->getCollection()->transform(
            fn (FoodItem $foodItem) =>
                $this->prepareFoodItemResponse($foodItem)
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
        $validator = Validator::make($request->all(), [
            'food_category_id' => [
                'required',
                'integer',
                'exists:food_categories,id',
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

            'description' => [
                'nullable',
                'string',
            ],

            /*
             * The frontend now sends an actual image file.
             */
            'image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],

            'price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'cost_price' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'unit' => [
                'nullable',
                'string',
                'max:50',
            ],

            'low_stock_quantity' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'status' => [
                'nullable',
                Rule::in([
                    FoodItem::STATUS_ACTIVE,
                    FoodItem::STATUS_INACTIVE,
                ]),
            ],

            'is_available' => [
                'nullable',
                'boolean',
            ],

            'preparation_time_minutes' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'sort_order' => [
                'nullable',
                'integer',
                'min:0',
            ],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        $imagePath = null;

        if ($request->hasFile('image')) {
            $imagePath = $request
                ->file('image')
                ->store('food-items', 'public');
        }

        $foodItem = FoodItem::create([
            'food_category_id' => $request->food_category_id,
            'name' => trim((string) $request->name),
            'slug' => $this->generateUniqueSlug(
                trim((string) $request->name)
            ),
            'sku' => $request->filled('sku')
                ? trim((string) $request->sku)
                : null,
            'description' => $request->description,
            'image' => $imagePath,

            'price' => $request->price,
            'cost_price' => $request->cost_price,
            'unit' => $request->unit ?? 'piece',

            'low_stock_quantity' =>
                $request->low_stock_quantity ?? 5,

            'status' =>
                $request->status ?? FoodItem::STATUS_ACTIVE,

            'is_available' => $request->has('is_available')
                ? $request->boolean('is_available')
                : true,

            'preparation_time_minutes' =>
                $request->preparation_time_minutes,

            'sort_order' => $request->sort_order ?? 0,

            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $foodItem->load([
            'category:id,name,slug,status',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendCreated(
            $this->prepareFoodItemResponse($foodItem),
            'Food item created successfully.'
        );
    }

    /**
     * Display one food item.
     */
    public function show(FoodItem $foodItem): JsonResponse
    {
        $foodItem->load([
            'category:id,name,slug,status',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse(
            $this->prepareFoodItemResponse($foodItem),
            'Food item retrieved successfully.'
        );
    }

    /**
     * Update a food item.
     */
    public function update(
        Request $request,
        FoodItem $foodItem
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'food_category_id' => [
                'required',
                'integer',
                'exists:food_categories,id',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('food_items', 'name')
                    ->ignore($foodItem->id),
            ],

            'sku' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('food_items', 'sku')
                    ->ignore($foodItem->id),
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],

            'price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'cost_price' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'unit' => [
                'nullable',
                'string',
                'max:50',
            ],

            'low_stock_quantity' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'status' => [
                'nullable',
                Rule::in([
                    FoodItem::STATUS_ACTIVE,
                    FoodItem::STATUS_INACTIVE,
                ]),
            ],

            'is_available' => [
                'nullable',
                'boolean',
            ],

            'preparation_time_minutes' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'sort_order' => [
                'nullable',
                'integer',
                'min:0',
            ],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        $imagePath = $foodItem->image;

        /*
         * Only replace the current image when a new file is uploaded.
         */
        if ($request->hasFile('image')) {
            $newImagePath = $request
                ->file('image')
                ->store('food-items', 'public');

            $this->deleteStoredImage($foodItem->image);

            $imagePath = $newImagePath;
        }

        $foodItem->update([
            'food_category_id' => $request->food_category_id,
            'name' => trim((string) $request->name),
            'slug' => $this->generateUniqueSlug(
                trim((string) $request->name),
                $foodItem->id
            ),
            'sku' => $request->filled('sku')
                ? trim((string) $request->sku)
                : null,
            'description' => $request->description,
            'image' => $imagePath,

            'price' => $request->price,
            'cost_price' => $request->cost_price,
            'unit' => $request->unit ?? $foodItem->unit,

            'low_stock_quantity' =>
                $request->low_stock_quantity ??
                $foodItem->low_stock_quantity,

            'status' =>
                $request->status ?? $foodItem->status,

            'is_available' => $request->has('is_available')
                ? $request->boolean('is_available')
                : $foodItem->is_available,

            'preparation_time_minutes' =>
                $request->preparation_time_minutes,

            'sort_order' =>
                $request->sort_order ??
                $foodItem->sort_order,

            'updated_by' => $request->user()->id,
        ]);

        $foodItem->load([
            'category:id,name,slug,status',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse(
            $this->prepareFoodItemResponse($foodItem),
            'Food item updated successfully.'
        );
    }

    /**
     * Delete a food item.
     *
     * The image is not removed here because the food item can
     * still be restored.
     */
    public function destroy(FoodItem $foodItem): JsonResponse
    {
        $foodItem->delete();

        return $this->sendResponse(
            [],
            'Food item deleted successfully.'
        );
    }

    /**
     * Restore a deleted food item.
     */
    public function restore(int $id): JsonResponse
    {
        $foodItem = FoodItem::onlyTrashed()->find($id);

        if (!$foodItem) {
            return $this->sendNotFound(
                'Deleted food item not found.'
            );
        }

        $foodItem->restore();

        $foodItem->load([
            'category:id,name,slug,status',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse(
            $this->prepareFoodItemResponse($foodItem),
            'Food item restored successfully.'
        );
    }

    /**
     * Change food item availability.
     */
    public function updateAvailability(
        Request $request,
        FoodItem $foodItem
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'is_available' => [
                'required',
                'boolean',
            ],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        $foodItem->update([
            'is_available' =>
                $request->boolean('is_available'),

            'updated_by' => $request->user()->id,
        ]);

        $foodItem->load([
            'category:id,name,slug,status',
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse(
            $this->prepareFoodItemResponse($foodItem),
            'Food item availability updated successfully.'
        );
    }

    /**
     * Add the complete public image URL to the API response.
     */
    private function prepareFoodItemResponse(
        FoodItem $foodItem
    ): FoodItem {
        $imageUrl = null;

        if ($foodItem->image) {
            if (
                Str::startsWith(
                    $foodItem->image,
                    ['http://', 'https://']
                )
            ) {
                $imageUrl = $foodItem->image;
            } else {
                $imageUrl = url(
                    Storage::disk('public')->url(
                        $foodItem->image
                    )
                );
            }
        }

        $foodItem->setAttribute('image_url', $imageUrl);

        return $foodItem;
    }

    /**
     * Delete an old locally stored food image.
     */
    private function deleteStoredImage(
        ?string $imagePath
    ): void {
        if (!$imagePath) {
            return;
        }

        if (
            Str::startsWith(
                $imagePath,
                ['http://', 'https://']
            )
        ) {
            return;
        }

        if (Storage::disk('public')->exists($imagePath)) {
            Storage::disk('public')->delete($imagePath);
        }
    }

    /**
     * Generate a unique slug.
     */
    private function generateUniqueSlug(
        string $name,
        ?int $ignoreId = null
    ): string {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $counter = 1;

        while (
            FoodItem::withTrashed()
                ->where('slug', $slug)
                ->when(
                    $ignoreId,
                    function ($query) use ($ignoreId) {
                        $query->where(
                            'id',
                            '!=',
                            $ignoreId
                        );
                    }
                )
                ->exists()
        ) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
