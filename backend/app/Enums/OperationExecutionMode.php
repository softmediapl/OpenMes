<?php

namespace App\Enums;

/**
 * Defines how an operation's standard duration scales with production volume.
 * The mode is snapshotted onto work orders so later template changes cannot
 * rewrite the planning assumptions used for released production.
 */
enum OperationExecutionMode: string
{
    case PerUnit = 'per_unit';
    case PerBatch = 'per_batch';
    case FixedHold = 'fixed_hold';
    case Setup = 'setup';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::PerUnit => __('Per unit'),
            self::PerBatch => __('Per batch'),
            self::FixedHold => __('Fixed hold'),
            self::Setup => __('Setup only'),
            self::Transfer => __('Transfer'),
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $mode) => $mode->value, self::cases());
    }
}
