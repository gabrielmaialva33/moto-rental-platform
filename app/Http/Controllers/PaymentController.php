<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProcessPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\Rental;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    /**
     * Process a payment for a rental.
     */
    public function processPayment(ProcessPaymentRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $data = $request->validated();
            $user = $request->user();
            $rental = Rental::findOrFail($data['rental_id']);

            // Create payment record
            $payment = Payment::create([
                'rental_id' => $rental->id,
                'user_id' => $user->id,
                'amount' => $data['amount'],
                'type' => $data['type'],
                'payment_method' => $data['payment_method'],
                'status' => 'pending',
                'description' => $data['description'] ?? $this->getDefaultDescription($data['type'], $rental),
                'gateway' => $this->getGatewayForMethod($data['payment_method']),
            ]);

            // Process payment based on method
            $gatewayResponse = $this->processPaymentByMethod($payment, $data);

            // Update payment with gateway response
            $payment->update([
                'gateway_response' => $gatewayResponse,
                'status' => $gatewayResponse['status'] ?? 'pending',
            ]);

            // Update rental payment status if this is the main rental payment
            if ($data['type'] === 'rental' && $gatewayResponse['status'] === 'completed') {
                $rental->update(['payment_status' => 'paid']);
                if ($rental->status === 'reserved') {
                    $rental->markAsActive();
                }
            }

            DB::commit();

            Log::info('Payment processed', [
                'payment_id' => $payment->id,
                'rental_id' => $rental->id,
                'amount' => $payment->amount,
                'method' => $payment->payment_method,
                'status' => $payment->status,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'message' => $this->getPaymentMessage($payment->payment_method, $payment->status),
                'data' => new PaymentResource($payment),
                'payment_info' => $this->getPaymentInstructions($payment, $gatewayResponse),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error processing payment', [
                'error' => $e->getMessage(),
                'data' => $request->validated(),
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'error' => 'Erro ao processar pagamento',
                'message' => 'Tente novamente em alguns instantes',
            ], 500);
        }
    }

    /**
     * Verify payment status (webhook or manual verification).
     */
    public function verifyPayment(Request $request, Payment $payment): JsonResponse
    {
        $request->validate([
            'gateway_transaction_id' => 'nullable|string',
            'verification_data' => 'nullable|array',
        ]);

        try {
            $user = $request->user();

            // Authorization check
            if (!$user->hasRole('admin') && $payment->user_id !== $user->id) {
                return response()->json([
                    'error' => 'Acesso negado',
                    'message' => 'Você não tem permissão para verificar este pagamento',
                ], 403);
            }

            // Verify payment status with gateway
            $verificationResult = $this->verifyPaymentWithGateway($payment, $request->all());

            // Update payment status
            if ($verificationResult['status'] !== $payment->status) {
                $payment->update([
                    'status' => $verificationResult['status'],
                    'gateway_response' => array_merge(
                        $payment->gateway_response ?? [],
                        $verificationResult
                    ),
                ]);

                // Update rental status if payment is completed
                if ($verificationResult['status'] === 'completed' && $payment->type === 'rental') {
                    $payment->rental->update(['payment_status' => 'paid']);
                    if ($payment->rental->status === 'reserved') {
                        $payment->rental->markAsActive();
                    }
                }

                Log::info('Payment status updated', [
                    'payment_id' => $payment->id,
                    'old_status' => $payment->getOriginal('status'),
                    'new_status' => $verificationResult['status'],
                    'verification_method' => 'manual',
                ]);
            }

            return response()->json([
                'message' => 'Status do pagamento verificado',
                'data' => new PaymentResource($payment->fresh()),
                'verification_result' => $verificationResult,
            ]);
        } catch (\Exception $e) {
            Log::error('Error verifying payment', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'error' => 'Erro ao verificar pagamento',
                'message' => 'Tente novamente em alguns instantes',
            ], 500);
        }
    }

    /**
     * Process refund for a payment.
     */
    public function refund(Request $request, Payment $payment): JsonResponse
    {
        $request->validate([
            'amount' => 'nullable|numeric|min:0.01|max:' . $payment->amount,
            'reason' => 'required|string|max:500',
        ]);

        DB::beginTransaction();

        try {
            $user = $request->user();

            // Authorization check (only admins can process refunds)
            if (!$user->hasRole('admin')) {
                return response()->json([
                    'error' => 'Acesso negado',
                    'message' => 'Apenas administradores podem processar reembolsos',
                ], 403);
            }

            // Validation checks
            if (!$payment->isCompleted()) {
                return response()->json([
                    'error' => 'Pagamento inválido',
                    'message' => 'Apenas pagamentos concluídos podem ser reembolsados',
                ], 422);
            }

            if ($payment->created_at->diffInDays(now()) > 30) {
                return response()->json([
                    'error' => 'Prazo excedido',
                    'message' => 'Reembolsos só podem ser processados até 30 dias após o pagamento',
                ], 422);
            }

            $refundAmount = $request->input('amount', $payment->amount);

            // Process refund with gateway
            $refundResult = $this->processRefundWithGateway($payment, $refundAmount, $request->reason);

            // Create refund payment record
            $refundPayment = Payment::create([
                'rental_id' => $payment->rental_id,
                'user_id' => $payment->user_id,
                'amount' => $refundAmount,
                'type' => 'refund',
                'payment_method' => $payment->payment_method,
                'status' => $refundResult['status'],
                'gateway' => $payment->gateway,
                'gateway_response' => $refundResult,
                'description' => "Reembolso do pagamento #{$payment->id} - {$request->reason}",
            ]);

            // Update original payment if fully refunded
            if ($refundAmount >= $payment->amount) {
                $payment->markAsRefunded();
            }

            DB::commit();

            Log::info('Refund processed', [
                'original_payment_id' => $payment->id,
                'refund_payment_id' => $refundPayment->id,
                'refund_amount' => $refundAmount,
                'reason' => $request->reason,
                'processed_by' => $user->id,
            ]);

            return response()->json([
                'message' => 'Reembolso processado com sucesso',
                'data' => new PaymentResource($refundPayment),
                'original_payment' => new PaymentResource($payment->fresh()),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error processing refund', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'data' => $request->all(),
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'error' => 'Erro ao processar reembolso',
                'message' => 'Tente novamente em alguns instantes',
            ], 500);
        }
    }

    /**
     * Get payment details including QR codes, barcodes, etc.
     */
    public function getPaymentDetails(Payment $payment): JsonResponse
    {
        try {
            $user = request()->user();

            // Authorization check
            if (!$user->hasRole('admin') && $payment->user_id !== $user->id) {
                return response()->json([
                    'error' => 'Acesso negado',
                    'message' => 'Você não tem permissão para visualizar este pagamento',
                ], 403);
            }

            $details = $this->getPaymentInstructions($payment, $payment->gateway_response ?? []);

            return response()->json([
                'data' => new PaymentResource($payment),
                'payment_details' => $details,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching payment details', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'user_id' => request()->user()->id,
            ]);

            return response()->json([
                'error' => 'Erro ao buscar detalhes do pagamento',
                'message' => 'Tente novamente em alguns instantes',
            ], 500);
        }
    }

    /**
     * Process payment based on method.
     */
    private function processPaymentByMethod(Payment $payment, array $data): array
    {
        return match ($payment->payment_method) {
            'pix' => $this->processPixPayment($payment, $data),
            'boleto' => $this->processBoletoPayment($payment, $data),
            'credit_card' => $this->processCreditCardPayment($payment, $data),
            default => throw new \InvalidArgumentException('Método de pagamento não suportado'),
        };
    }

    /**
     * Process PIX payment.
     */
    private function processPixPayment(Payment $payment, array $data): array
    {
        // Simulate PIX payment processing
        $qrCode = 'PIX' . strtoupper(Str::random(32));
        $pixKey = config('app.pix_key', 'contato@motoaluguel.com.br');

        return [
            'status' => 'pending',
            'method' => 'pix',
            'qr_code' => $qrCode,
            'pix_key' => $pixKey,
            'expires_at' => now()->addMinutes(30)->toISOString(),
            'instructions' => [
                'Abra o app do seu banco',
                'Escaneie o QR Code ou copie e cole a chave PIX',
                'Confirme o pagamento de R$ ' . number_format($payment->amount, 2, ',', '.'),
                'O pagamento será confirmado automaticamente',
            ],
        ];
    }

    /**
     * Process Boleto payment.
     */
    private function processBoletoPayment(Payment $payment, array $data): array
    {
        // Simulate Boleto generation
        $barcode = '23793.' . str_pad(rand(10000, 99999), 5, '0', STR_PAD_LEFT) .
                   ' ' . str_pad(rand(10000, 99999), 5, '0', STR_PAD_LEFT) .
                   ' ' . str_pad(rand(100000000, 999999999), 9, '0', STR_PAD_LEFT);

        $dueDate = $data['due_date'] ?? now()->addDays(3)->format('Y-m-d');

        return [
            'status' => 'pending',
            'method' => 'boleto',
            'barcode' => $barcode,
            'due_date' => $dueDate,
            'bank_url' => 'https://simulador-boleto.exemplo.com/' . $payment->transaction_id,
            'instructions' => [
                'Acesse o link do boleto ou utilize o código de barras',
                'Pague até ' . \Carbon\Carbon::parse($dueDate)->format('d/m/Y'),
                'O pagamento pode levar até 2 dias úteis para ser confirmado',
                'Guarde o comprovante de pagamento',
            ],
        ];
    }

    /**
     * Process Credit Card payment.
     */
    private function processCreditCardPayment(Payment $payment, array $data): array
    {
        // Simulate credit card processing
        $cardNumber = $data['card_number'];
        $lastFourDigits = substr($cardNumber, -4);
        $cardBrand = $this->getCardBrand($cardNumber);

        // Simulate approval (90% chance of approval for demo)
        $isApproved = rand(1, 10) <= 9;

        if ($isApproved) {
            return [
                'status' => 'completed',
                'method' => 'credit_card',
                'card_brand' => $cardBrand,
                'last_four_digits' => $lastFourDigits,
                'installments' => $data['installments'] ?? 1,
                'authorization_code' => 'AUTH' . strtoupper(Str::random(6)),
                'nsu' => str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT),
                'transaction_id' => 'TXN' . time() . rand(1000, 9999),
            ];
        } else {
            return [
                'status' => 'failed',
                'method' => 'credit_card',
                'error_code' => '51',
                'error_message' => 'Transação negada pelo banco emissor',
                'card_brand' => $cardBrand,
                'last_four_digits' => $lastFourDigits,
            ];
        }
    }

    /**
     * Verify payment with gateway.
     */
    private function verifyPaymentWithGateway(Payment $payment, array $verificationData): array
    {
        // Simulate payment verification
        return match ($payment->payment_method) {
            'pix' => [
                'status' => rand(1, 10) <= 7 ? 'completed' : 'pending', // 70% chance of completion
                'verified_at' => now()->toISOString(),
                'verification_method' => 'api_check',
            ],
            'boleto' => [
                'status' => rand(1, 10) <= 5 ? 'completed' : 'pending', // 50% chance of completion
                'verified_at' => now()->toISOString(),
                'verification_method' => 'banking_system',
            ],
            'credit_card' => [
                'status' => $payment->status, // Credit card payments are immediate
                'verified_at' => now()->toISOString(),
                'verification_method' => 'real_time',
            ],
        };
    }

    /**
     * Process refund with gateway.
     */
    private function processRefundWithGateway(Payment $payment, float $amount, string $reason): array
    {
        // Simulate refund processing
        return [
            'status' => 'completed', // Assume refunds are always successful for demo
            'refund_id' => 'REF' . strtoupper(Str::random(10)),
            'amount' => $amount,
            'reason' => $reason,
            'processed_at' => now()->toISOString(),
            'estimated_arrival' => match ($payment->payment_method) {
                'pix' => now()->addMinutes(5)->toISOString(),
                'credit_card' => now()->addDays(7)->toISOString(),
                'boleto' => now()->addDays(2)->toISOString(),
            },
        ];
    }

    /**
     * Get gateway for payment method.
     */
    private function getGatewayForMethod(string $method): string
    {
        return match ($method) {
            'pix' => 'pix_gateway',
            'boleto' => 'boleto_gateway',
            'credit_card' => 'card_gateway',
            default => 'unknown',
        };
    }

    /**
     * Get default payment description.
     */
    private function getDefaultDescription(string $type, Rental $rental): string
    {
        return match ($type) {
            'rental' => "Pagamento da locação #{$rental->id}",
            'deposit' => "Caução da locação #{$rental->id}",
            'additional' => "Taxas adicionais da locação #{$rental->id}",
            'refund' => "Reembolso da locação #{$rental->id}",
            default => "Pagamento da locação #{$rental->id}",
        };
    }

    /**
     * Get payment instructions based on method and status.
     */
    private function getPaymentInstructions(Payment $payment, array $gatewayResponse): array
    {
        $instructions = [];

        if ($payment->status === 'pending') {
            $instructions = $gatewayResponse['instructions'] ?? [];
        }

        return [
            'method' => $payment->payment_method,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'transaction_id' => $payment->transaction_id,
            'instructions' => $instructions,
            'gateway_data' => $this->sanitizeGatewayData($gatewayResponse),
            'expires_at' => $gatewayResponse['expires_at'] ?? null,
        ];
    }

    /**
     * Sanitize gateway data for public display.
     */
    private function sanitizeGatewayData(array $gatewayResponse): array
    {
        // Remove sensitive data from gateway response
        $sensitiveFields = ['card_number', 'cvv', 'authorization_code', 'nsu'];

        return array_diff_key($gatewayResponse, array_flip($sensitiveFields));
    }

    /**
     * Get payment success/error message.
     */
    private function getPaymentMessage(string $method, string $status): string
    {
        if ($status === 'completed') {
            return 'Pagamento processado com sucesso!';
        }

        return match ($method) {
            'pix' => 'PIX gerado com sucesso. Escaneie o QR Code para pagar.',
            'boleto' => 'Boleto gerado com sucesso. Pague até a data de vencimento.',
            'credit_card' => $status === 'failed'
                ? 'Pagamento recusado. Verifique os dados do cartão.'
                : 'Processando pagamento...',
            default => 'Pagamento em processamento.',
        };
    }

    /**
     * Get card brand from card number.
     */
    private function getCardBrand(string $cardNumber): string
    {
        $cardNumber = preg_replace('/\D/', '', $cardNumber);

        if (preg_match('/^4/', $cardNumber)) {
            return 'visa';
        } elseif (preg_match('/^5[1-5]/', $cardNumber)) {
            return 'mastercard';
        } elseif (preg_match('/^3[47]/', $cardNumber)) {
            return 'amex';
        } elseif (preg_match('/^6011|^65/', $cardNumber)) {
            return 'discover';
        }

        return 'unknown';
    }
}
