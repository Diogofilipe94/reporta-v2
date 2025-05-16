<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreReportDetailRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();
        return in_array($user->role->role, ['admin', 'curator']);
    }

    public function rules(): array
    {
        return [
            'technical_description' => 'required|string|min:10',
            'priority' => 'required|in:baixa,média,alta,Baixa,Média,Alta,media,Media',
            'resolution_notes' => 'nullable|string',
            'estimated_cost' => 'nullable|numeric|min:0'
        ];
    }

    public function messages(): array
    {
        return [
            'technical_description.required' => 'A descrição técnica é obrigatória',
            'technical_description.min' => 'A descrição técnica deve ter pelo menos 10 caracteres',
            'priority.required' => 'A prioridade é obrigatória',
            'priority.in' => 'A prioridade deve ser baixa, média ou alta',
            'estimated_cost.numeric' => 'O custo estimado deve ser um número',
            'estimated_cost.min' => 'O custo estimado não pode ser negativo'
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
}
