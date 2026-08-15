<?php

namespace App\Sync\Shapes;

use App\Models\User;
use App\Sync\Shape;

class UnitsOfMeasureShape extends Shape
{
    public function table(): string
    {
        return 'units_of_measure';
    }

    public function columns(): array
    {
        return [
            'id', 'tenant_id', 'code', 'name', 'symbol', 'quantity_precision',
            'is_active', 'created_at', 'updated_at',
        ];
    }

    public function where(User $user): ?string
    {
        return 'tenant_id = '.(int) $user->tenant_id;
    }
}
