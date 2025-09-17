<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessPaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'rental_id' => 'required|exists:rentals,id',
            'amount' => 'required|numeric|min:0.01|max:99999.99',
            'type' => 'required|in:rental,deposit,additional,refund',
            'payment_method' => 'required|in:pix,boleto,credit_card',
            'description' => 'nullable|string|max:255',
        ];

        // Additional validation based on payment method
        if ($this->payment_method === 'credit_card') {
            $rules = array_merge($rules, [
                'card_number' => 'required|string|size:16|regex:/^[0-9]+$/',
                'card_holder_name' => 'required|string|max:255',
                'card_expiry_month' => 'required|integer|between:1,12',
                'card_expiry_year' => 'required|integer|min:' . date('Y') . '|max:' . (date('Y') + 10),
                'card_cvv' => 'required|string|size:3|regex:/^[0-9]+$/',
                'installments' => 'nullable|integer|between:1,12',
            ]);
        }

        if ($this->payment_method === 'pix') {
            $rules = array_merge($rules, [
                'pix_key' => 'nullable|string|max:255',
            ]);
        }

        if ($this->payment_method === 'boleto') {
            $rules = array_merge($rules, [
                'due_date' => 'required|date|after:today|before:' . now()->addDays(30)->format('Y-m-d'),
            ]);
        }

        return $rules;
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Verify rental belongs to user or user is admin
            if ($this->rental_id) {
                $rental = \App\Models\Rental::find($this->rental_id);
                if ($rental &&
                    $rental->user_id !== $this->user()->id &&
                    !$this->user()->hasRole('admin')) {
                    $validator->errors()->add('rental_id', 'Você não tem autorização para processar pagamentos desta locação.');
                }
            }

            // Validate card number using Luhn algorithm for credit card
            if ($this->payment_method === 'credit_card' && $this->card_number) {
                if (!$this->isValidCardNumber($this->card_number)) {
                    $validator->errors()->add('card_number', 'Número do cartão inválido.');
                }
            }

            // Validate CVV based on card type
            if ($this->payment_method === 'credit_card' && $this->card_cvv) {
                $cardType = $this->getCardType($this->card_number);
                if ($cardType === 'amex' && strlen($this->card_cvv) !== 4) {
                    $validator->errors()->add('card_cvv', 'CVV deve ter 4 dígitos para American Express.');
                } elseif ($cardType !== 'amex' && strlen($this->card_cvv) !== 3) {
                    $validator->errors()->add('card_cvv', 'CVV deve ter 3 dígitos.');
                }
            }
        });
    }

    /**
     * Validate credit card number using Luhn algorithm.
     */
    private function isValidCardNumber(string $number): bool
    {
        $number = preg_replace('/\D/', '', $number);
        $sum = 0;
        $alternate = false;

        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $digit = intval($number[$i]);

            if ($alternate) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit = ($digit % 10) + 1;
                }
            }

            $sum += $digit;
            $alternate = !$alternate;
        }

        return ($sum % 10) === 0;
    }

    /**
     * Get card type based on number.
     */
    private function getCardType(string $number): string
    {
        $number = preg_replace('/\D/', '', $number);

        if (preg_match('/^3[47]/', $number)) {
            return 'amex';
        } elseif (preg_match('/^4/', $number)) {
            return 'visa';
        } elseif (preg_match('/^5[1-5]/', $number)) {
            return 'mastercard';
        }

        return 'unknown';
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rental_id.required' => 'ID da locação é obrigatório.',
            'rental_id.exists' => 'Locação não encontrada.',
            'amount.required' => 'Valor é obrigatório.',
            'amount.min' => 'Valor deve ser maior que zero.',
            'amount.max' => 'Valor máximo excedido.',
            'type.required' => 'Tipo de pagamento é obrigatório.',
            'type.in' => 'Tipo de pagamento inválido.',
            'payment_method.required' => 'Método de pagamento é obrigatório.',
            'payment_method.in' => 'Método de pagamento inválido.',
            'card_number.required' => 'Número do cartão é obrigatório.',
            'card_number.size' => 'Número do cartão deve ter 16 dígitos.',
            'card_number.regex' => 'Número do cartão deve conter apenas números.',
            'card_holder_name.required' => 'Nome do portador é obrigatório.',
            'card_expiry_month.required' => 'Mês de expiração é obrigatório.',
            'card_expiry_month.between' => 'Mês de expiração inválido.',
            'card_expiry_year.required' => 'Ano de expiração é obrigatório.',
            'card_expiry_year.min' => 'Ano de expiração não pode ser no passado.',
            'card_cvv.required' => 'CVV é obrigatório.',
            'card_cvv.size' => 'CVV deve ter 3 dígitos.',
            'card_cvv.regex' => 'CVV deve conter apenas números.',
            'installments.between' => 'Número de parcelas deve ser entre 1 e 12.',
            'due_date.required' => 'Data de vencimento é obrigatória.',
            'due_date.after' => 'Data de vencimento deve ser futura.',
            'due_date.before' => 'Data de vencimento deve ser dentro de 30 dias.',
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
            'rental_id' => 'locação',
            'amount' => 'valor',
            'type' => 'tipo',
            'payment_method' => 'método de pagamento',
            'card_number' => 'número do cartão',
            'card_holder_name' => 'nome do portador',
            'card_expiry_month' => 'mês de expiração',
            'card_expiry_year' => 'ano de expiração',
            'card_cvv' => 'CVV',
            'installments' => 'parcelas',
            'pix_key' => 'chave PIX',
            'due_date' => 'data de vencimento',
        ];
    }
}
