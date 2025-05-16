<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateReportStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();
        return in_array($user->role->role, ['admin', 'curator']);
    }

    public function rules(): array
    {
        return [
            'status_id' => 'required|exists:statuses,id'
        ];
    }

    public function messages(): array
    {
        return [
            'status_id.required' => 'O status é obrigatório',
            'status_id.exists' => 'O status selecionado não existe'
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422)
        );
    }

    protected function failedAuthorization()
    {
        throw new HttpResponseException(
            response()->json([
                'error' => 'Unauthorized. Only admin or curator can update status.'
            ], 403)
        );
    }
}
