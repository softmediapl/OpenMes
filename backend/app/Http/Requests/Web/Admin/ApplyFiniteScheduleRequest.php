<?php

namespace App\Http\Requests\Web\Admin;

class ApplyFiniteScheduleRequest extends ProposeFiniteScheduleRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }
}
