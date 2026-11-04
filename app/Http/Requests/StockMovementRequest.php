<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockMovementRequest extends FormRequest
{
    /**
     * The forms staff can record, and the stock authority each needs.
     *
     * @var array<string, string>
     */
    public const AUTHORITY = [
        'receive' => 'clerk',
        'transfer' => 'clerk',
        'return' => 'clerk',
        'adjust' => 'manager',
        'writeoff' => 'manager',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request. Each form adds its own fields to the shared line.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $type = $this->input('type');

        return [
            'type' => ['required', Rule::in(array_keys(self::AUTHORITY))],
            'reward_id' => ['required', 'integer', Rule::exists('rewards', 'id')],
            'variant_id' => ['nullable', 'integer'],
            'location_id' => ['required', 'integer', Rule::exists('stock_locations', 'id')],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'batch' => ['nullable', 'string', 'max:64'],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
            'serials' => ['nullable', 'array', 'max:1000'],
            'serials.*' => ['string', 'max:64'],
            'reference' => ['nullable', 'string', 'max:64'],
            'redemption_id' => ['nullable', 'integer', Rule::exists('redemptions', 'id')],
            'refund_points' => ['sometimes', 'boolean'],
            'reason' => [in_array($type, ['adjust', 'writeoff', 'return'], true) ? 'required' : 'nullable', 'string', 'max:255'],
            'source' => [Rule::requiredIf($type === 'receive'), Rule::in(['PURCHASE', 'DONATION'])],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'unit_cost' => [Rule::requiredIf($type === 'receive'), 'numeric', 'min:0', 'max:9999999'],
            'direction' => [Rule::requiredIf($type === 'adjust'), Rule::in(['in', 'out'])],
            'to_location_id' => [Rule::requiredIf($type === 'transfer'), 'integer', 'different:location_id', Rule::exists('stock_locations', 'id')],
            'kind' => [Rule::requiredIf($type === 'writeoff'), Rule::in(['DAMAGE', 'LOSS', 'EXPIRY'])],
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
            'reason.required' => 'A reason is required for this movement.',
            'quantity.min' => 'Enter a whole quantity of at least 1.',
            'quantity.max' => 'Quantity is too large.',
            'to_location_id.different' => 'Choose a different destination.',
            'unit_cost.min' => 'Enter a unit cost of zero or more.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_map(
            fn ($value) => is_string($value) ? trim($value) : $value,
            $this->only(['batch', 'reference', 'reason']),
        ));
    }
}
