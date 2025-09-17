<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'amount' => $this->amount,
            'type' => $this->type,
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'description' => $this->description,
            'rental' => $this->when($this->relationLoaded('rental'), [
                'id' => $this->rental->id,
                'start_date' => $this->rental->start_date->format('Y-m-d'),
                'end_date' => $this->rental->end_date->format('Y-m-d'),
                'motorcycle' => $this->rental->motorcycle ? [
                    'id' => $this->rental->motorcycle->id,
                    'brand' => $this->rental->motorcycle->brand,
                    'model' => $this->rental->motorcycle->model,
                ] : null,
            ]),
            'payment_details' => $this->getPaymentDetails(),
            'gateway_info' => $this->when($request->user()?->hasRole('admin'), [
                'gateway' => $this->gateway,
                'gateway_response' => $this->gateway_response,
            ]),
            'dates' => [
                'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
                'paid_at' => $this->paid_at?->format('Y-m-d H:i:s'),
                'refunded_at' => $this->refunded_at?->format('Y-m-d H:i:s'),
                'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            ],
            'status_info' => [
                'is_pending' => $this->isPending(),
                'is_completed' => $this->isCompleted(),
                'is_failed' => $this->isFailed(),
                'is_refunded' => $this->status === 'refunded',
                'can_refund' => $this->canBeRefunded(),
            ],
        ];
    }

    /**
     * Get payment method specific details.
     */
    private function getPaymentDetails(): array
    {
        $details = [
            'method' => $this->payment_method,
            'formatted_method' => $this->getFormattedPaymentMethod(),
        ];

        // Add method-specific information without sensitive data
        switch ($this->payment_method) {
            case 'pix':
                $details['pix'] = [
                    'qr_code_available' => $this->status === 'pending',
                    'expires_at' => $this->created_at?->addMinutes(30)->format('Y-m-d H:i:s'),
                ];
                break;

            case 'boleto':
                $details['boleto'] = [
                    'due_date' => $this->gateway_response['due_date'] ?? null,
                    'barcode_available' => $this->status === 'pending',
                ];
                break;

            case 'credit_card':
                $details['credit_card'] = [
                    'installments' => $this->gateway_response['installments'] ?? 1,
                    'card_brand' => $this->gateway_response['card_brand'] ?? null,
                    'last_four_digits' => $this->gateway_response['last_four_digits'] ?? null,
                ];
                break;
        }

        return $details;
    }

    /**
     * Get formatted payment method name.
     */
    private function getFormattedPaymentMethod(): string
    {
        return match ($this->payment_method) {
            'pix' => 'PIX',
            'boleto' => 'Boleto Bancário',
            'credit_card' => 'Cartão de Crédito',
            default => ucfirst($this->payment_method),
        };
    }

    /**
     * Check if payment can be refunded.
     */
    private function canBeRefunded(): bool
    {
        return $this->isCompleted() &&
               $this->type !== 'refund' &&
               $this->created_at->diffInDays(now()) <= 30; // 30 days limit for refunds
    }

    /**
     * Get additional data to be returned with the resource array.
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'currency' => 'BRL',
                'type_descriptions' => [
                    'rental' => 'Pagamento da locação',
                    'deposit' => 'Caução/Depósito',
                    'additional' => 'Taxas adicionais',
                    'refund' => 'Reembolso',
                ],
                'status_descriptions' => [
                    'pending' => 'Aguardando pagamento',
                    'completed' => 'Pago',
                    'failed' => 'Falha no pagamento',
                    'refunded' => 'Reembolsado',
                ],
            ],
        ];
    }
}
