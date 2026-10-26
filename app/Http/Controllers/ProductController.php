<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /**
     * The relations every product response includes.
     *
     * @var list<string>
     */
    private const RELATIONS = ['category:id,name,name_kh,is_active', 'images'];

    /**
     * Request fields that are handled as image changes rather than product columns.
     *
     * @var list<string>
     */
    private const IMAGE_FIELDS = ['images', 'remove_image_ids'];

    /**
     * List a page of products with their category and images, optionally filtered by category, search term or featured flag.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt('Could not load products.', function () use ($request): JsonResponse {
            $filters = $request->validate([
                'category_id' => ['nullable', 'integer'],
                'search' => ['nullable', 'string', 'max:255'],
                'is_featured' => ['nullable', 'boolean'],
            ]);
            $perPage = $this->perPage($request);

            $products = Product::query()
                ->with(self::RELATIONS)
                ->when($filters['category_id'] ?? null, fn ($query, int $categoryId) => $query->where('category_id', $categoryId))
                ->when($filters['search'] ?? null, function ($query, string $search) {
                    $term = '%'.mb_strtolower($search).'%';
                    $query->where(fn ($query) => $query
                        ->whereRaw('lower(name) like ?', [$term])
                        ->orWhereRaw('lower(sku) like ?', [$term])
                        ->orWhere('name_kh', 'like', "%{$search}%"));
                })
                ->when(isset($filters['is_featured']), fn ($query) => $query->where('is_featured', $request->boolean('is_featured')))
                ->latest('id')
                ->paginate($perPage);

            return $this->paginatedResponse($products, 'Products retrieved successfully');
        });
    }

    /**
     * Create a product with any uploaded images, generating a SKU when none is given.
     */
    public function store(ProductRequest $request): JsonResponse
    {
        return $this->attempt('Could not create the product.', function () use ($request): JsonResponse {
            $product = DB::transaction(function () use ($request): Product {
                $product = Product::create([
                    'sku' => 'PRD-'.Str::upper(Str::random(8)),
                    ...$request->safe()->except(self::IMAGE_FIELDS),
                ]);
                $product->addImages($request->file('images') ?? []);

                return $product;
            });

            return response()->json([
                'success' => true,
                'message' => 'Product created successfully',
                'data' => $product->refresh()->load(self::RELATIONS),
            ], 201);
        });
    }

    /**
     * Show a product.
     */
    public function show(Product $product): JsonResponse
    {
        return $this->attempt('Could not load the product.', fn (): JsonResponse => response()->json([
            'success' => true,
            'message' => 'Product retrieved successfully',
            'data' => $product->load(self::RELATIONS),
        ]));
    }

    /**
     * Update a product. `remove_image_ids` deletes images; `images` uploads more after the existing ones.
     * Send files as multipart POST with `_method=PATCH`, since PHP does not read multipart PATCH bodies.
     */
    public function update(ProductRequest $request, Product $product): JsonResponse
    {
        return $this->attempt('Could not update the product.', function () use ($request, $product): JsonResponse {
            DB::transaction(function () use ($request, $product): void {
                $product->update($request->safe()->except(self::IMAGE_FIELDS));
                $product->removeImages($request->input('remove_image_ids') ?? []);
                $product->addImages($request->file('images') ?? []);
            });

            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => $product->load(self::RELATIONS),
            ]);
        });
    }

    /**
     * Soft delete a product. Its images and its order, stock and purchase history stay with the archived row.
     */
    public function destroy(Product $product): JsonResponse
    {
        return $this->attempt('Could not delete the product.', function () use ($product): JsonResponse {
            $product->delete();

            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully',
            ]);
        });
    }
}
