<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;


class StoreAddressRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'street' => 'required|string|max:64',
            'number' => 'required|string|max:10',
            'city' => 'required|string|max:32',
            'cp' => 'required|string|min:8|max:8',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'errors' => $validator->errors(),
            ], 422));
    }
    public function messages(): array
    {
        return [
            'street.required' => 'A rua é obrigatória',
            'number.required' => 'O número é obrigatório',
            'city.required' => 'A cidade é obrigatória',
            'cp.required' => 'O código postal é obrigatório',
            'cp.min' => 'O código postal deve ter 7 caracteres separados por -',
            'cp.max' => 'O código postal deve ter 7 caracteres separados por -',
        ];
    }
}
