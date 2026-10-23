<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Product;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductRequest extends FormRequest
{
    /**
     * The largest image upload accepted, in kilobytes (4 MB).
     */
    public const MAX_IMAGE_KILOBYTES = 4096;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request. PATCH updates only the fields sent.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $product = $this->route('product');
        $presence = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'category_id' => [$presence, 'integer', Rule::exists('categories', 'id')->withoutTrashed(), $this->assignableCategory($product)],
            'name' => [$presence, 'string', 'max:255'],
            'name_kh' => ['nullable', 'string', 'max:255'],
            'sku' => ['sometimes', 'string', 'max:64', Rule::unique('products', 'sku')->ignore($product)],
            'description' => ['nullable', 'string', 'max:5000'],
            'images' => ['sometimes', 'array', 'max:'.Product::MAX_IMAGES],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_IMAGE_KILOBYTES],
            'remove_image_ids' => ['sometimes', 'array'],
            'remove_image_ids.*' => ['integer', Rule::exists('product_images', 'id')->where('product_id', $product?->id ?? 0)],
            'price' => [$presence, 'numeric', 'gt:0', 'max:9999999999.99'],
            'cost_price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'reorder_level' => ['sometimes', 'integer', 'min:0'],
            'normal_points' => ['sometimes', 'integer', 'min:0'],
            'bonus_multiplier' => ['sometimes', 'numeric', 'min:1', 'max:999.99'],
            'is_featured' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Prepare the data for validation. A blank SKU is dropped so one is generated on create.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_map(
            fn ($value) => is_string($value) ? trim($value) : $value,
            $this->only(['name', 'name_kh', 'sku', 'description']),
        ));

        if ($this->has('sku') && blank($this->input('sku'))) {
            $this->getInputSource()->remove('sku');
        }
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $product = $this->route('product');
                $kept = $product ? $product->images()->whereKeyNot($this->input('remove_image_ids', []))->count() : 0;
                $total = $kept + count($this->file('images', []));

                if ($total > Product::MAX_IMAGES) {
                    $validator->errors()->add('images', 'A product can have at most '.Product::MAX_IMAGES." images; this would make {$total}.");
                }
            },
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'images.max' => 'Upload at most '.Product::MAX_IMAGES.' images at a time.',
            'images.*.image' => 'Each file must be an image.',
            'images.*.mimes' => 'Images must be JPG, PNG or WebP.',
            'images.*.max' => 'Each image must be 4 MB or smaller.',
            'remove_image_ids.*.exists' => 'That image does not belong to this product.',
        ];
    }

    /**
     * Inactive categories cannot take new products, but a product may stay in the one it already has.
     */
    private function assignableCategory(?Product $product): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($product): void {
            $category = Category::find($value);

            if ($category && ! $category->is_active && $product?->category_id !== $category->id) {
                $fail("The {$category->name} category is inactive and cannot be assigned to products.");
            }
        };
    }
}
