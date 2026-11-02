<?php

namespace App\Http\Requests;

use App\Models\Tier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TierRequest extends FormRequest
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
        $tier = $this->route('tier');
        $presence = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'name' => [$presence, 'string', 'max:50', $this->uniqueName($tier)],
            'min_points' => [$presence, 'integer', 'min:0', 'max:2000000000'],
            'max_points' => ['nullable', 'integer', 'max:2000000000'],
            'multiplier' => [$presence, 'numeric', 'between:1,10'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
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
            'color.regex' => 'The color must be a hex color such as #C0C0C0.',
        ];
    }

    /**
     * Check the tier's point range against its own bounds and every other tier, once the fields themselves are valid.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Tier|null $tier */
                $tier = $this->route('tier');
                $min = (int) ($this->has('min_points') ? $this->input('min_points') : $tier?->min_points);
                $max = $this->has('max_points') ? $this->input('max_points') : $tier?->max_points;
                $max = $max === null ? null : (int) $max;

                if ($max !== null && $max <= $min) {
                    $validator->errors()->add('max_points', 'The maximum points must be greater than the minimum points.');

                    return;
                }

                $others = Tier::query()->when($tier, fn ($query) => $query->whereKeyNot($tier->id));

                if ($max === null && (clone $others)->whereNull('max_points')->exists()) {
                    $validator->errors()->add('max_points', 'Only one tier can have no upper limit. Give the other open-ended tier a maximum first.');

                    return;
                }

                $overlap = $others
                    ->when($max !== null, fn ($query) => $query->where('min_points', '<=', $max))
                    ->where(fn ($query) => $query->whereNull('max_points')->orWhere('max_points', '>=', $min))
                    ->orderBy('min_points')
                    ->first();

                if ($overlap) {
                    $range = number_format($overlap->min_points).($overlap->max_points === null ? '+' : '–'.number_format($overlap->max_points));
                    $validator->errors()->add('min_points', "This point range overlaps the {$overlap->name} tier ({$range}).");
                }
            },
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_map(
            fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value,
            $this->only(['name', 'color', 'max_points']),
        ));
    }

    /**
     * Tier names are unique regardless of letter case.
     */
    private function uniqueName(?Tier $tier): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($tier): void {
            $taken = Tier::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                ->when($tier, fn ($query) => $query->whereKeyNot($tier->id))
                ->exists();

            if ($taken) {
                $fail("A tier named {$value} already exists.");
            }
        };
    }
}
