<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class SystemSetting
{
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $value = DB::table('system_settings')->where('key', $key)->value('value');
        } catch (\Throwable) {
            return $default;
        }

        if ($value === null) {
            return $default;
        }

        $decoded = json_decode((string) $value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    public static function boolean(string $key, bool $default = false): bool
    {
        return filter_var(self::get($key, $default), FILTER_VALIDATE_BOOL);
    }

    public static function integer(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function put(string $key, mixed $value, ?string $description = null): void
    {
        $attributes = [
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ];

        if ($description !== null) {
            $attributes['description'] = $description;
        }

        DB::table('system_settings')->updateOrInsert(['key' => $key], $attributes);
    }

    /** @param array<string, mixed> $settings */
    public static function putMany(array $settings): void
    {
        DB::transaction(function () use ($settings): void {
            foreach ($settings as $key => $value) {
                self::put($key, $value);
            }
        });
    }
}
