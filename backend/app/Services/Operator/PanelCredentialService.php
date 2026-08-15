<?php

namespace App\Services\Operator;

use App\Models\User;
use App\Support\SystemSetting;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PanelCredentialService
{
    public function length(): int
    {
        return max(9, min(12, SystemSetting::integer('panel_pin_length', 9)));
    }

    public function lookup(string $pin, ?int $tenantId): string
    {
        return hash_hmac('sha256', ($tenantId ?? 'landlord').'|'.$pin, (string) config('app.key'));
    }

    public function find(string $pin, ?int $tenantId): ?User
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->where('pin_lookup', $this->lookup($pin, $tenantId))
            ->first();
    }

    public function set(User $user, string $pin): void
    {
        $lookup = $this->lookup($pin, $user->tenant_id);
        $duplicate = User::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('pin_lookup', $lookup)
            ->whereKeyNot($user->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['pin' => __('This panel PIN is already assigned.')]);
        }

        $user->forceFill([
            'pin' => Hash::make($pin),
            'pin_lookup' => $lookup,
            'pin_rotated_at' => now(),
        ])->save();
    }

    public function generate(User $user): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $pin = implode('', array_map(fn () => (string) random_int(0, 9), range(1, $this->length())));
            if (! $this->find($pin, $user->tenant_id)) {
                $this->set($user, $pin);

                return $pin;
            }
        }

        throw new \RuntimeException('Could not generate a unique panel credential.');
    }

    public function remove(User $user): void
    {
        $user->forceFill([
            'pin' => null,
            'pin_lookup' => null,
            'pin_rotated_at' => null,
        ])->save();
    }
}
