<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'group',
        'key',
        'value',
        'type',
    ];

    /** Read a typed setting value. */
    public static function get(string $key, mixed $default = null, string $group = 'general'): mixed
    {
        $setting = static::where('group', $group)->where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        return match ($setting->type) {
            'int' => (int) $setting->value,
            'bool' => filter_var($setting->value, FILTER_VALIDATE_BOOL),
            'json' => json_decode((string) $setting->value, true),
            default => $setting->value,
        };
    }

    /** Create or update a typed setting value. */
    public static function set(string $key, mixed $value, string $type = 'string', string $group = 'general'): self
    {
        $stored = $type === 'json' ? json_encode($value) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);

        return static::updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $stored, 'type' => $type],
        );
    }
}
