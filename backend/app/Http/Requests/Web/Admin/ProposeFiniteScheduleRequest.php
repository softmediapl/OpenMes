<?php

namespace App\Http\Requests\Web\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProposeFiniteScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'line_id' => [
                'required',
                'integer',
                Rule::exists('lines', 'id')->where('is_active', true),
            ],
            'requested_start_at' => ['required', 'date'],
        ];
    }
}
