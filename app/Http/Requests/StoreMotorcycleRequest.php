<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMotorcycleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only admins can create motorcycles
        return $this->user() && $this->user()->hasRole('admin');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'brand' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'year' => 'required|integer|min:1990|max:' . date('Y'),
            'plate' => 'required|string|max:10|unique:motorcycles,plate',
            'color' => 'required|string|max:100',
            'engine_capacity' => 'required|integer|min:50|max:2000',
            'mileage' => 'required|integer|min:0',
            'daily_rate' => 'required|numeric|min:0.01|max:9999.99',
            'status' => 'required|in:available,rented,maintenance,inactive',
            'description' => 'nullable|string|max:1000',
            'features' => 'nullable|array',
            'features.*' => 'string|max:255',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,png,jpg,webp|max:2048',
            'last_maintenance_at' => 'nullable|date|before_or_equal:today',
            'next_maintenance_at' => 'nullable|date|after:today',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plate.unique' => 'Esta placa já está cadastrada no sistema.',
            'year.max' => 'O ano não pode ser superior ao ano atual.',
            'year.min' => 'O ano deve ser 1990 ou superior.',
            'engine_capacity.min' => 'A cilindrada deve ser de pelo menos 50cc.',
            'engine_capacity.max' => 'A cilindrada não pode exceder 2000cc.',
            'daily_rate.min' => 'A diária deve ser maior que zero.',
            'images.*.image' => 'Cada arquivo deve ser uma imagem válida.',
            'images.*.max' => 'Cada imagem deve ter no máximo 2MB.',
            'last_maintenance_at.before_or_equal' => 'A data da última manutenção não pode ser futura.',
            'next_maintenance_at.after' => 'A próxima manutenção deve ser agendada para uma data futura.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'brand' => 'marca',
            'model' => 'modelo',
            'year' => 'ano',
            'plate' => 'placa',
            'color' => 'cor',
            'engine_capacity' => 'cilindrada',
            'mileage' => 'quilometragem',
            'daily_rate' => 'diária',
            'status' => 'status',
            'description' => 'descrição',
            'features' => 'características',
            'images' => 'imagens',
            'last_maintenance_at' => 'última manutenção',
            'next_maintenance_at' => 'próxima manutenção',
        ];
    }
}
