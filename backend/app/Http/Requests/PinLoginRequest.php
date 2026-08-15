<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PinLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => 'required|string',
            'pin' => 'required|string|digits_between:4,12',
        ];
    }

    public function messages(): array
    {
        return [
            'pin.digits_between' => 'PIN must contain 4–12 digits.',
        ];
    }
}
