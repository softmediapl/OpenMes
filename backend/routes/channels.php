<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('col.{tenant}.work_orders_active', function ($user, string $tenant) {
    if (! $user) {
        return false;
    }

    $userTenant = $user->tenant_id === null ? 'g' : (string) $user->tenant_id;

    return $tenant === $userTenant
        && $user->hasAnyRole(['Admin', 'Supervisor', 'Operator']);
});

/**
 * Synced-collection channels (Reverb). Private channel per collection, namespaced
 * by tenant so a user only receives their own tenant's rows (tenantKey = the
 * user's tenant_id, or "g" for null — mirrors the null-safe TenantScope).
 *
 * This is the read-path authorization the Electric shapes never had.
 */
Broadcast::channel('col.{tenant}.{collection}', function ($user, string $tenant, string $collection) {
    if (! $user) {
        return false;
    }

    $userTenant = $user->tenant_id === null ? 'g' : (string) $user->tenant_id;
    // A user may subscribe to their own tenant channel AND the global ("g")
    // channel: global tables (no tenant_id column — e.g. skills, issues) and
    // global lookup rows are visible to every tenant, mirroring the null-safe
    // TenantScope and CollectionController's snapshot. Without the "g" allowance
    // a tenant user is denied live updates for every global collection, so rows
    // created/deleted on those tables never reflect without a full reload.
    if ($tenant !== $userTenant && $tenant !== 'g') {
        return false;
    }

    // Admin lists remain Admin/Supervisor only.
    return $user->hasAnyRole(['Admin', 'Supervisor']);
});
