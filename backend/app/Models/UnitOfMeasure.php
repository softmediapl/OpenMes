<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class UnitOfMeasure extends Model
{
    use HasFactory;

    protected $table = 'units_of_measure';

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'quantity_precision',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'quantity_precision' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public static function precisionForCode(?string $code): int
    {
        $code = trim((string) $code);
        $precision = self::query()->where('code', $code)->value('quantity_precision');

        if ($code === '' || $precision === null) {
            throw new RuntimeException("Unit of measure '{$code}' is not configured.");
        }

        $precision = (int) $precision;
        if ($precision < 0 || $precision > 4) {
            throw new RuntimeException("Unit of measure '{$code}' has invalid quantity precision.");
        }

        return $precision;
    }

}
