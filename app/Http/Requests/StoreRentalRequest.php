<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class StoreRentalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // User must be authenticated and have a valid CNH
        $user = $this->user();
        return $user &&
               $user->cnh_number &&
               $user->cnh_expiry_date &&
               Carbon::parse($user->cnh_expiry_date)->isFuture() &&
               $user->age >= 18;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'motorcycle_id' => [
                'required',
                'exists:motorcycles,id',
                Rule::exists('motorcycles', 'id')->where('status', 'available')
            ],
            'start_date' => 'required|date|after:today',
            'end_date' => 'required|date|after:start_date',
            'pickup_location' => 'required|string|max:255',
            'return_location' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:500',
            'insurance_details' => 'nullable|array',
            'insurance_details.type' => 'nullable|in:basic,premium,full',
            'insurance_details.deductible' => 'nullable|numeric|min:0',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Check if motorcycle is available for the requested dates
            if ($this->motorcycle_id && $this->start_date && $this->end_date) {
                $conflictingRentals = \App\Models\Rental::where('motorcycle_id', $this->motorcycle_id)
                    ->whereIn('status', ['reserved', 'active'])
                    ->where(function ($query) {
                        $query->whereBetween('start_date', [$this->start_date, $this->end_date])
                              ->orWhereBetween('end_date', [$this->start_date, $this->end_date])
                              ->orWhere(function ($subQuery) {
                                  $subQuery->where('start_date', '<=', $this->start_date)
                                           ->where('end_date', '>=', $this->end_date);
                              });
                    })
                    ->exists();

                if ($conflictingRentals) {
                    $validator->errors()->add('start_date', 'A motocicleta não está disponível para o período selecionado.');
                }
            }

            // Validate minimum rental period (1 day)
            if ($this->start_date && $this->end_date) {
                $startDate = Carbon::parse($this->start_date);
                $endDate = Carbon::parse($this->end_date);

                if ($startDate->diffInDays($endDate) < 1) {
                    $validator->errors()->add('end_date', 'O período mínimo de locação é de 1 dia.');
                }

                // Validate maximum rental period (30 days)
                if ($startDate->diffInDays($endDate) > 30) {
                    $validator->errors()->add('end_date', 'O período máximo de locação é de 30 dias.');
                }
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motorcycle_id.required' => 'Selecione uma motocicleta.',
            'motorcycle_id.exists' => 'A motocicleta selecionada não existe ou não está disponível.',
            'start_date.required' => 'A data de início é obrigatória.',
            'start_date.after' => 'A data de início deve ser posterior a hoje.',
            'end_date.required' => 'A data de fim é obrigatória.',
            'end_date.after' => 'A data de fim deve ser posterior à data de início.',
            'pickup_location.required' => 'O local de retirada é obrigatório.',
            'pickup_location.max' => 'O local de retirada deve ter no máximo 255 caracteres.',
            'return_location.max' => 'O local de devolução deve ter no máximo 255 caracteres.',
            'notes.max' => 'As observações devem ter no máximo 500 caracteres.',
            'insurance_details.type.in' => 'Tipo de seguro inválido.',
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
            'motorcycle_id' => 'motocicleta',
            'start_date' => 'data de início',
            'end_date' => 'data de fim',
            'pickup_location' => 'local de retirada',
            'return_location' => 'local de devolução',
            'notes' => 'observações',
            'insurance_details' => 'detalhes do seguro',
        ];
    }
}
