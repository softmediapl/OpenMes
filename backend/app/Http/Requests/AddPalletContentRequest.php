<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddPalletContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'batch_step_id' => ['required', 'integer', 'exists:batch_steps,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
