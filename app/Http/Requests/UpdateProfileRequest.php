<?php
namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => 'sometimes|string|max:32',
            'last_name' => 'sometimes|string|max:32',
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:64',
                Rule::unique('users')->ignore(auth()->id())
            ],
            'password' => 'sometimes|string|min:8',
            'telephone' => 'sometimes|regex:/^[0-9]{9}$/',
            'address_id' => 'sometimes|exists:addresses,id',
        ];
    }

    protected function failedValidation(Validator $validator) {
        throw new HttpResponseException(
            response()->json([
                'errors' => $validator->errors(),
            ], 422)
        );
    }

    public function messages(): array
    {
        return [
            'first_name.string' => 'O primeiro nome deve ser uma string',
            'first_name.max' => 'O primeiro nome não pode exceder 32 caracteres',
            'last_name.string' => 'O último nome deve ser uma string',
            'last_name.max' => 'O último nome não pode exceder 32 caracteres',
            'email.email' => 'O email deve ser um endereço de email válido',
            'email.max' => 'O email não pode exceder 64 caracteres',
            'email.unique' => 'O email já está em uso',
            'password.min' => 'A palavra-passe deve ter pelo menos 8 caracteres',
            'telephone.regex' => 'O número de telemóvel deve ter 9 dígitos',
            'address_id.exists' => 'O endereço não existe',
        ];
    }
}
