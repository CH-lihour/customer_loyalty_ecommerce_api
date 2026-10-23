<?php

namespace App\Http\Requests;

use App\Models\Category;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
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
        $category = $this->route('category');
        $presence = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->withoutTrashed(), Rule::notIn([$category?->id])],
            'name' => [$presence, 'string', 'max:40', $this->uniqueName($category)],
            'name_kh' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
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
            'parent_id.not_in' => 'A category cannot be its own parent.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_map(
            fn ($value) => is_string($value) ? trim($value) : $value,
            $this->only(['name', 'name_kh', 'description']),
        ));
    }

    /**
     * Category names are unique regardless of letter case.
     */
    private function uniqueName(?Category $category): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($category): void {
            $taken = Category::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                ->when($category, fn ($query) => $query->whereKeyNot($category->id))
                ->exists();

            if ($taken) {
                $fail("A category named {$value} already exists.");
            }
        };
    }
}
