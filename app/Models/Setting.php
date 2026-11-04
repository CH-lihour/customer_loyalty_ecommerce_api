<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A system-wide value, stored as text under a unique key.
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public const EXCHANGE_RATE = 'exchange_rate';

    public const RESERVATION_HOURS = 'reservation_hours';

    /**
     * The value of each setting until staff change it, matching the frontend's defaults.
     *
     * @var array<string, int>
     */
    public const DEFAULTS = [self::EXCHANGE_RATE => 4000, self::RESERVATION_HOURS => 72];

    /**
     * Read a whole-number setting, or its default.
     */
    public static function integer(string $key): int
    {
        return (int) (self::query()->where('key', $key)->value('value') ?? self::DEFAULTS[$key]);
    }

    /**
     * Save a setting.
     */
    public static function put(string $key, int|string $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }
}
