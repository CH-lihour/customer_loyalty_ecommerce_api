<?php

namespace App\Http\Requests;

use App\Models\RewardStockItem;
use App\Models\RewardVariant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StockItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request. The whole settings record is sent on every save.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $reward = $this->route('reward');

        return [
            'sku' => ['required', 'string', 'max:64', Rule::unique('reward_stock_items', 'sku')->ignore($reward->id, 'reward_id')],
            'unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'tracking' => ['required', Rule::in(RewardStockItem::TRACKING)],
            'low_stock_threshold' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reorder_point' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reorder_qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'out_of_stock' => ['required', Rule::in(['hide', 'disable'])],
            'allow_backorder' => ['required', 'boolean'],
            'variants' => ['present', 'array', 'max:100'],
            'variants.*.id' => ['nullable', 'integer', Rule::exists('reward_variants', 'id')->where('reward_id', $reward->id)],
            'variants.*.size' => ['nullable', 'string', 'max:40'],
            'variants.*.color' => ['nullable', 'string', 'max:40'],
            'variants.*.sku' => ['nullable', 'string', 'max:64'],
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
            'sku.unique' => 'Another reward already uses that SKU.',
            'reorder_qty.min' => 'Reorder quantity must be at least 1.',
        ];
    }

    /**
     * Each variant needs a size or colour, and variant SKUs must be unique across every reward.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $variants = $this->variantRows();

                if ($variants->contains(fn (array $variant) => $variant['size'] === '' && $variant['color'] === '')) {
                    $validator->errors()->add('variants', 'Each variant needs a size or colour.');

                    return;
                }

                $skus = $variants->pluck('sku');
                $taken = RewardVariant::query()
                    ->whereIn('sku', $skus)
                    ->where('reward_id', '!=', $this->route('reward')->id)
                    ->exists();

                if ($skus->unique()->count() !== $skus->count() || $taken) {
                    $validator->errors()->add('variants', 'Variant SKUs must be unique.');
                }
            },
        ];
    }

    /**
     * The variants with trimmed fields and upper-case SKUs; a blank SKU becomes "<item SKU>-<n>".
     *
     * @return Collection<int, array{id: int|null, size: string, color: string, sku: string}>
     */
    public function variantRows(): Collection
    {
        $sku = Str::upper(trim((string) $this->input('sku')));

        return collect($this->input('variants', []))->values()->map(fn (array $variant, int $index) => [
            'id' => isset($variant['id']) ? (int) $variant['id'] : null,
            'size' => trim((string) ($variant['size'] ?? '')),
            'color' => trim((string) ($variant['color'] ?? '')),
            'sku' => Str::upper(trim((string) ($variant['sku'] ?? ''))) ?: "{$sku}-".($index + 1),
        ]);
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => Str::upper(trim($this->input('sku')))]);
        }
    }
}
