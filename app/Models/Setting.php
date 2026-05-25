<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value', 'group'])]
class Setting extends Model
{
    protected static array $cache = [];

    /**
     * Get a setting by key.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key] ?? $default;
        }

        $setting = self::query()->where('key', $key)->first();
        self::$cache[$key] = $setting?->value;

        return $setting?->value ?? $default;
    }

    /**
     * Set/Update a setting by key.
     */
    public static function set(string $key, ?string $value, ?string $group = null): void
    {
        self::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group]
        );
        self::$cache[$key] = $value;
    }

    /**
     * Clear settings local cache.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
