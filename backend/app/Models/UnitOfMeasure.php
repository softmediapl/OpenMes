<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnitOfMeasure extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id',
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

    public static function defaultDefinitions(): array
    {
        return [
            ['code' => 'pcs', 'name' => 'Pieces', 'symbol' => 'pcs', 'quantity_precision' => 0],
            ['code' => 'kg', 'name' => 'Kilograms', 'symbol' => 'kg', 'quantity_precision' => 4],
            ['code' => 'g', 'name' => 'Grams', 'symbol' => 'g', 'quantity_precision' => 3],
            ['code' => 'l', 'name' => 'Litres', 'symbol' => 'l', 'quantity_precision' => 4],
            ['code' => 'ml', 'name' => 'Millilitres', 'symbol' => 'ml', 'quantity_precision' => 2],
            ['code' => 'm', 'name' => 'Metres', 'symbol' => 'm', 'quantity_precision' => 4],
            ['code' => 'cm', 'name' => 'Centimetres', 'symbol' => 'cm', 'quantity_precision' => 2],
            ['code' => 'm2', 'name' => 'Square metres', 'symbol' => 'm²', 'quantity_precision' => 4],
            ['code' => 'm3', 'name' => 'Cubic metres', 'symbol' => 'm³', 'quantity_precision' => 4],
        ];
    }

    public static function seedDefaultsForTenant(int $tenantId): void
    {
        foreach (self::defaultDefinitions() as $definition) {
            self::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenantId, 'code' => $definition['code']],
                [...$definition, 'is_active' => true],
            );
        }
    }

    public static function inferredPrecision(?string $code): int
    {
        return in_array(strtolower(trim((string) $code)), [
            'pc', 'pcs', 'piece', 'pieces', 'szt', 'szt.', 'sztuka', 'sztuki', 'unit', 'units',
        ], true) ? 0 : 4;
    }

    public static function ensureCode(?string $code): ?self
    {
        $code = trim((string) $code);
        $tenantId = auth()->hasUser() ? auth()->user()?->tenant_id : app(TenantContext::class)->id();
        if ($code === '' || ! $tenantId) {
            return null;
        }

        $definition = collect(self::defaultDefinitions())->firstWhere('code', $code) ?? [
            'code' => $code,
            'name' => $code,
            'symbol' => $code,
            'quantity_precision' => self::inferredPrecision($code),
        ];

        return self::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'code' => $code],
            [...$definition, 'is_active' => true],
        );
    }
}
