<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\FoodCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class FoodCategoryController extends BaseController
{
    /**
     * Display all food categories.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FoodCategory::query()
            ->with([
                'createdBy:id,name,email,phone,role',
                'updatedBy:id,name,email,phone,role',
            ])
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }

        $perPage = $request->get('per_page', 20);

        $categories = $query->paginate($perPage);

        return $this->sendResponse($categories, 'Food categories retrieved successfully.');
    }

    /**
     * Store a new food category.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255|unique:food_categories,name',
            'description' => 'nullable|string',
            'image'       => 'nullable|string|max:255',
            'status'      => 'nullable|in:active,inactive',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $category = FoodCategory::create([
            'name'        => $request->name,
            'slug'        => $this->generateUniqueSlug($request->name),
            'description' => $request->description,
            'image'       => $request->image,
            'status'      => $request->status ?? FoodCategory::STATUS_ACTIVE,
            'sort_order'  => $request->sort_order ?? 0,
            'created_by'  => $request->user()->id,
            'updated_by'  => $request->user()->id,
        ]);

        $category->load([
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendCreated($category, 'Food category created successfully.');
    }

    /**
     * Display one food category.
     */
    public function show(FoodCategory $foodCategory): JsonResponse
    {
        $foodCategory->load([
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($foodCategory, 'Food category retrieved successfully.');
    }

    /**
     * Update a food category.
     */
    public function update(Request $request, FoodCategory $foodCategory): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255|unique:food_categories,name,' . $foodCategory->id,
            'description' => 'nullable|string',
            'image'       => 'nullable|string|max:255',
            'status'      => 'nullable|in:active,inactive',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors(), 'Please check your input.');
        }

        $foodCategory->update([
            'name'        => $request->name,
            'slug'        => $this->generateUniqueSlug($request->name, $foodCategory->id),
            'description' => $request->description,
            'image'       => $request->image,
            'status'      => $request->status ?? $foodCategory->status,
            'sort_order'  => $request->sort_order ?? $foodCategory->sort_order,
            'updated_by'  => $request->user()->id,
        ]);

        $foodCategory->load([
            'createdBy:id,name,email,phone,role',
            'updatedBy:id,name,email,phone,role',
        ]);

        return $this->sendResponse($foodCategory, 'Food category updated successfully.');
    }

    /**
     * Delete a food category.
     */
    public function destroy(FoodCategory $foodCategory): JsonResponse
    {
        $foodCategory->delete();

        return $this->sendResponse([], 'Food category deleted successfully.');
    }

    /**
     * Restore deleted food category.
     */
    public function restore(int $id): JsonResponse
    {
        $category = FoodCategory::onlyTrashed()->find($id);

        if (!$category) {
            return $this->sendNotFound('Deleted food category not found.');
        }

        $category->restore();

        return $this->sendResponse($category, 'Food category restored successfully.');
    }

    /**
     * Generate unique slug.
     */
    private function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $counter = 1;

        while (
            FoodCategory::where('slug', $slug)
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