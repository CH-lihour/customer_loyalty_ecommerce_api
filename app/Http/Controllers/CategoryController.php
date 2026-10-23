<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * List a page of categories with their product counts, optionally filtered by search term or status.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $perPage = $this->perPage($request);

        $categories = Category::query()
            ->withCount('products')
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(fn ($query) => $query->whereRaw('lower(name) like ?', [$term])->orWhere('name_kh', 'like', "%{$search}%"));
            })
            ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage);

        return $this->paginatedResponse($categories, 'Categories retrieved successfully');
    }

    /**
     * Create a category.
     */
    public function store(CategoryRequest $request): JsonResponse
    {
        $category = Category::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully',
            'data' => $category->refresh()->loadCount('products'),
        ], 201);
    }

    /**
     * Show a category.
     */
    public function show(Category $category): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Category retrieved successfully',
            'data' => $category->load('parent:id,name')->loadCount('products'),
        ]);
    }

    /**
     * Update a category; PATCH with only `is_active` toggles its status.
     */
    public function update(CategoryRequest $request, Category $category): JsonResponse
    {
        $category->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully',
            'data' => $category->loadCount('products'),
        ]);
    }

    /**
     * Delete a category that no longer holds any products.
     */
    public function destroy(Category $category): JsonResponse
    {
        $productCount = $category->products()->count();

        if ($productCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Move the {$productCount} ".str('product')->plural($productCount)." in {$category->name} to another category first.",
            ], 422);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully',
        ]);
    }
}
