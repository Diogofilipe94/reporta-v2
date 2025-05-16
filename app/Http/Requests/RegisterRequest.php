<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class RegisterRequest extends FormRequest
{
    public function authorize(): true
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => 'required|string|max:32',
            'last_name' => 'required|string|max:32',
            'email' => 'required|string|email|max:64|unique:users',
            'password' => 'required|string|min:8',
            'telephone' => 'required|regex:/^[0-9]{9}$/',
            'address_id' => 'required|exists:addresses,id',
        ];
    }

    protected function failedValidation(Validator $validator) {
        throw new HttpResponseException(
            response()->json([
                'errors' => $validator->errors(),
            ], 422));
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'O primeiro nome é obrigatório',
            'last_name.required' => 'O último nome é obrigatório',
            'email.required' => 'O email é obrigatório',
            'email.email' => 'O email deve ser um endereço de email válido',
            'email.unique' => 'O email já está em uso',
            'password.required' => 'A palavra-passe é obrigatória',
            'password.min' => 'A palavra-passe deve ter pelo menos 8 caracteres',
            'telephone.required' => 'O número de telemóvel é obrigatório',
            'telephone.regex' => 'O número de telemóvel deve ter 9 dígitos',
            'address_id.required' => 'O endereço é obrigatório',
            'address_id.exists' => 'O endereço não existe',
        ];
    }
}
