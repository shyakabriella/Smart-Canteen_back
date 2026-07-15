<?php

namespace App\Http\Controllers\API;

use App\Models\FoodCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FoodCategoryController extends BaseController
{
    /**
     * Display food categories.
     *
     * Students only receive active, non-deleted categories.
     * Admin/staff may filter status and include soft-deleted records.
     */
    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();
        $canManage = $authUser?->canManageInventory() ?? false;

        $query = FoodCategory::query()
            ->with([
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
        } else {
            $query->where('status', FoodCategory::STATUS_ACTIVE);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%');
            });
        }

        $perPage = min(max((int) $request->get('per_page', 20), 1), 200);
        $categories = $query->paginate($perPage);

        $categories->getCollection()->transform(
            fn (FoodCategory $category) => $this->prepareCategoryResponse($category)
        );

        return $this->sendResponse(
            $categories,
            'Food categories retrieved successfully.'
        );
    }

    /**
     * Store a new food category.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can create food categories.'
            );
        }

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('food_categories', 'name'),
            ],
            'description' => ['nullable', 'string'],
            'image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
            'status' => [
                'nullable',
                Rule::in([
                    FoodCategory::STATUS_ACTIVE,
                    FoodCategory::STATUS_INACTIVE,
                ]),
            ],
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
                'food-categories',
                'public'
            );
        }

        $name = trim((string) $request->name);

        $category = FoodCategory::create([
            'name' => $name,
            'slug' => $this->generateUniqueSlug($name),
            'description' => $request->description,
            'image' => $imagePath,
            'status' => $request->status ?? FoodCategory::STATUS_ACTIVE,
            'sort_order' => $request->sort_order ?? 0,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $category->load([
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendCreated(
            $this->prepareCategoryResponse($category),
            'Food category created successfully.'
        );
    }

    /**
     * Display one food category.
     */
    public function show(
        Request $request,
        FoodCategory $foodCategory
    ): JsonResponse {
        if (
            !($request->user()?->canManageInventory() ?? false) &&
            $foodCategory->status !== FoodCategory::STATUS_ACTIVE
        ) {
            return $this->sendNotFound('Food category not found.');
        }

        $foodCategory->load([
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse(
            $this->prepareCategoryResponse($foodCategory),
            'Food category retrieved successfully.'
        );
    }

    /**
     * Update a food category.
     *
     * Supports PUT and PATCH without clearing omitted fields.
     */
    public function update(
        Request $request,
        FoodCategory $foodCategory
    ): JsonResponse {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can update food categories.'
            );
        }

        $validator = Validator::make($request->all(), [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('food_categories', 'name')
                    ->ignore($foodCategory->id),
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
            'status' => [
                'sometimes',
                'required',
                Rule::in([
                    FoodCategory::STATUS_ACTIVE,
                    FoodCategory::STATUS_INACTIVE,
                ]),
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

        if ($request->has('name')) {
            $name = trim((string) $request->name);
            $data['name'] = $name;
            $data['slug'] = $this->generateUniqueSlug(
                $name,
                $foodCategory->id
            );
        }

        if ($request->has('description')) {
            $data['description'] = $request->description;
        }

        if ($request->has('status')) {
            $data['status'] = $request->status;
        }

        if ($request->has('sort_order')) {
            $data['sort_order'] = (int) $request->sort_order;
        }

        if ($request->boolean('remove_image')) {
            $this->deleteStoredImage($foodCategory->image);
            $data['image'] = null;
        } elseif ($request->hasFile('image')) {
            $newImagePath = $request->file('image')->store(
                'food-categories',
                'public'
            );

            $this->deleteStoredImage($foodCategory->image);
            $data['image'] = $newImagePath;
        }

        $data['updated_by'] = $request->user()->id;

        $foodCategory->update($data);

        $foodCategory->load([
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse(
            $this->prepareCategoryResponse($foodCategory),
            'Food category updated successfully.'
        );
    }

    /**
     * Soft-delete a food category.
     */
    public function destroy(
        Request $request,
        FoodCategory $foodCategory
    ): JsonResponse {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can delete food categories.'
            );
        }

        if (
            method_exists($foodCategory, 'foodItems') &&
            $foodCategory->foodItems()->whereNull('deleted_at')->exists()
        ) {
            return $this->sendError(
                'This category still contains food items. Move or delete the food items first.',
                [],
                400
            );
        }

        $foodCategory->delete();

        return $this->sendResponse(
            [],
            'Food category deleted successfully.'
        );
    }

    /**
     * Restore a deleted food category.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageInventory()) {
            return $this->sendForbidden(
                'Only admin or staff can restore food categories.'
            );
        }

        $category = FoodCategory::onlyTrashed()->find($id);

        if (!$category) {
            return $this->sendNotFound(
                'Deleted food category not found.'
            );
        }

        $category->restore();

        $category->load([
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse(
            $this->prepareCategoryResponse($category),
            'Food category restored successfully.'
        );
    }

    /**
     * Add the public image URL to the category response.
     */
    private function prepareCategoryResponse(
        FoodCategory $category
    ): FoodCategory {
        $imageUrl = null;

        if ($category->image) {
            if (Str::startsWith($category->image, ['http://', 'https://'])) {
                $imageUrl = $category->image;
            } else {
                $imageUrl = url(
                    Storage::disk('public')->url($category->image)
                );
            }
        }

        $category->setAttribute('image_url', $imageUrl);

        return $category;
    }

    /**
     * Delete an old locally stored image.
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
        $slug = $baseSlug !== '' ? $baseSlug : 'category';
        $originalSlug = $slug;
        $counter = 1;

        while (
            FoodCategory::withTrashed()
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