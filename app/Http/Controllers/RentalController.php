<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRentalRequest;
use App\Http\Resources\RentalResource;
use App\Models\Motorcycle;
use App\Models\Payment;
use App\Models\Rental;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RentalController extends Controller
{
    /**
     * Display a listing of user's rentals with filters.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $query = Rental::query()->with(['motorcycle', 'payments']);

            // Admin can see all rentals, users can only see their own
            if (!$user->hasRole('admin')) {
                $query->where('user_id', $user->id);
            }

            // Apply filters
            $this->applyFilters($query, $request);

            // Apply sorting
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $allowedSortFields = ['created_at', 'start_date', 'end_date', 'status', 'total_amount'];

            if (in_array($sortBy, $allowedSortFields)) {
                $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
            }

            // Paginate results
            $perPage = min($request->input('per_page', 15), 50);
            $rentals = $query->paginate($perPage);

            return response()->json([
                'data' => RentalResource::collection($rentals),
                'pagination' => [
                    'current_page' => $rentals->currentPage(),
                    'last_page' => $rentals->lastPage(),
                    'per_page' => $rentals->perPage(),
                    'total' => $rentals->total(),
                    'from' => $rentals->firstItem(),
                    'to' => $rentals->lastItem(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching rentals', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'filters' => $request->all(),
            ]);

            return response()->json([
                'error' => 'Erro ao buscar locações',
                'message' => 'Tente novamente em alguns instantes',
            ], 500);
        }
    }

    /**
     * Store a newly created rental.
     */
    public function store(StoreRentalRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $data = $request->validated();
            $user = $request->user();
            $motorcycle = Motorcycle::findOrFail($data['motorcycle_id']);

            // Calculate pricing
            $pricing = $this->calculateRentalPricing(
                $motorcycle,
                Carbon::parse($data['start_date']),
                Carbon::parse($data['end_date'])
            );

            // Create rental
            $rental = Rental::create([
                'user_id' => $user->id,
                'motorcycle_id' => $data['motorcycle_id'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'daily_rate' => $motorcycle->daily_rate,
                'total_amount' => $pricing['total_amount'],
                'security_deposit' => $pricing['security_deposit'],
                'discount' => $pricing['discount'],
                'status' => 'reserved',
                'payment_status' => 'pending',
                'pickup_location' => $data['pickup_location'],
                'return_location' => $data['return_location'] ?? $data['pickup_location'],
                'notes' => $data['notes'] ?? null,
                'insurance_details' => $data['insurance_details'] ?? null,
            ]);

            // Mark motorcycle as rented
            $motorcycle->markAsRented();

            // Create initial payment record
            Payment::create([
                'rental_id' => $rental->id,
                'user_id' => $user->id,
                'amount' => $rental->total_amount,
                'type' => 'rental',
                'payment_method' => 'pending', // Will be updated when payment method is chosen
                'status' => 'pending',
                'description' => "Pagamento da locação #{$rental->id}",
            ]);

            DB::commit();

            Log::info('Rental created', [
                'rental_id' => $rental->id,
                'user_id' => $user->id,
                'motorcycle_id' => $motorcycle->id,
                'total_amount' => $rental->total_amount,
            ]);

            return response()->json([
                'message' => 'Locação criada com sucesso',
                'data' => new RentalResource($rental->load(['motorcycle', 'payments'])),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error creating rental', [
                'error' => $e->getMessage(),
                'data' => $request->validated(),
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'error' => 'Erro ao criar locação',
                'message' => 'Tente novamente em alguns instantes',
            ], 500);
        }
    }

    /**
     * Display the specified rental with payment info.
     */
    public function show(Rental $rental): JsonResponse
    {
        try {
            $user = request()->user();

            // Authorization check
            if (!$user->hasRole('admin') && $rental->user_id !== $user->id) {
                return response()->json([
                    'error' => 'Acesso negado',
                    'message' => 'Você não tem permissão para visualizar esta locação',
                ], 403);
            }

            $rental->load(['motorcycle', 'user', 'payments']);

            return response()->json([
                'data' => new RentalResource($rental),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching rental', [
                'rental_id' => $rental->id,
                'error' => $e->getMessage(),
                'user_id' => request()->user()->id,
            ]);

            return response()->json([
                'error' => 'Erro ao buscar locação',
                'message' => 'Tente novamente em alguns instantes',
            ], 500);
        }
    }

    /**
     * Complete a rental with final calculations.
     */
    public function complete(Request $request, Rental $rental): JsonResponse
    {
        $request->validate([
            'final_mileage' => 'required|integer|min:' . ($rental->initial_mileage ?? 0),
            'additional_charges' => 'nullable|numeric|min:0',
            'additional_charges_description' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();

        try {
            $user = $request->user();

            // Authorization check
            if (!$user->hasRole('admin') && $rental->user_id !== $user->id) {
                return response()->json([
                    'error' => 'Acesso negado',
                    'message' => 'Você não tem permissão para finalizar esta locação',
                ], 403);
            }

            // Validation check
            if (!in_array($rental->status, ['active'])) {
                return response()->json([
                    'error' => 'Status inválido',
                    'message' => 'Apenas locações ativas podem ser finalizadas',
                ], 422);
            }

            // Calculate additional charges for overdue
            $additionalCharges = $request->input('additional_charges', 0);
            if ($rental->isOverdue()) {
                $overdueDays = $rental->end_date->diffInDays(now());
                $overdueCharges = $overdueDays * ($rental->daily_rate * 0.5); // 50% penalty
                $additionalCharges += $overdueCharges;
            }

            // Update rental
            $rental->update([
                'status' => 'completed',
                'actual_return_date' => now(),
                'final_mileage' => $request->final_mileage,
                'additional_charges' => $additionalCharges,
                'additional_charges_description' => $request->additional_charges_description,
                'notes' => $request->notes,
            ]);

            // Update motorcycle status and mileage
            $rental->motorcycle->update([
                'status' => 'available',
                'mileage' => $request->final_mileage,
            ]);

            // Create additional charges payment if needed
            if ($additionalCharges > 0) {
                Payment::create([
                    'rental_id' => $rental->id,
                    'user_id' => $rental->user_id,
                    'amount' => $additionalCharges,
                    'type' => 'additional',
                    'payment_method' => 'pending',
                    'status' => 'pending',
                    'description' => 'Taxas adicionais - ' . $request->additional_charges_description,
                ]);
            }

            DB::commit();

            Log::info('Rental completed', [
                'rental_id' => $rental->id,
                'final_mileage' => $request->final_mileage,
                'additional_charges' => $additionalCharges,
                'completed_by' => $user->id,
            ]);

            return response()->json([
                'message' => 'Locação finalizada com sucesso',
                'data' => new RentalResource($rental->fresh()->load(['motorcycle', 'payments'])),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error completing rental', [
                'rental_id' => $rental->id,
                'error' => $e->getMessage(),
                'data' => $request->all(),
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'error' => 'Erro ao finalizar locação',
                'message' => 'Tente novamente em alguns instantes',
            ], 500);
        }
    }

    /**
     * Cancel a rental with refund calculation.
     */
    public function cancel(Request $request, Rental $rental): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        DB::beginTransaction();

        try {
            $user = $request->user();

            // Authorization check
            if (!$user->hasRole('admin') && $rental->user_id !== $user->id) {
                return response()->json([
                    'error' => 'Acesso negado',
                    'message' => 'Você não tem permissão para cancelar esta locação',
                ], 403);
            }

            // Validation check
            if (!in_array($rental->status, ['reserved'])) {
                return response()->json([
                    'error' => 'Status inválido',
                    'message' => 'Apenas locações reservadas podem ser canceladas',
                ], 422);
            }

            // Calculate cancellation fees
            $refundCalculation = $this->calculateCancellationRefund($rental);

            // Update rental
            $rental->update([
                'status' => 'cancelled',
                'notes' => ($rental->notes ? $rental->notes . "\n\n" : '') .
                          "Cancelado em " . now()->format('d/m/Y H:i') .
                          " - Motivo: " . $request->reason,
            ]);

            // Mark motorcycle as available
            $rental->motorcycle->markAsAvailable();

            // Process refund if applicable
            if ($refundCalculation['refund_amount'] > 0) {
                Payment::create([
                    'rental_id' => $rental->id,
                    'user_id' => $rental->user_id,
                    'amount' => $refundCalculation['refund_amount'],
                    'type' => 'refund',
                    'payment_method' => 'pending',
                    'status' => 'pending',
                    'description' => 'Reembolso do cancelamento - ' . $request->reason,
                ]);
            }

            // Add cancellation fee if applicable
            if ($refundCalculation['cancellation_fee'] > 0) {
                $rental->update([
                    'additional_charges' => $refundCalculation['cancellation_fee'],
                    'additional_charges_description' => 'Taxa de cancelamento',
                ]);
            }

            DB::commit();

            Log::info('Rental cancelled', [
                'rental_id' => $rental->id,
                'reason' => $request->reason,
                'refund_amount' => $refundCalculation['refund_amount'],
                'cancellation_fee' => $refundCalculation['cancellation_fee'],
                'cancelled_by' => $user->id,
            ]);

            return response()->json([
                'message' => 'Locação cancelada com sucesso',
                'data' => new RentalResource($rental->fresh()->load(['motorcycle', 'payments'])),
                'refund_info' => $refundCalculation,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error cancelling rental', [
                'rental_id' => $rental->id,
                'error' => $e->getMessage(),
                'data' => $request->all(),
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'error' => 'Erro ao cancelar locação',
                'message' => 'Tente novamente em alguns instantes',
            ], 500);
        }
    }

    /**
     * Calculate rental price with discounts.
     */
    public function calculatePrice(Request $request): JsonResponse
    {
        $request->validate([
            'motorcycle_id' => 'required|exists:motorcycles,id',
            'start_date' => 'required|date|after:today',
            'end_date' => 'required|date|after:start_date',
            'insurance_type' => 'nullable|in:basic,premium,full',
        ]);

        try {
            $motorcycle = Motorcycle::findOrFail($request->motorcycle_id);
            $startDate = Carbon::parse($request->start_date);
            $endDate = Carbon::parse($request->end_date);

            $pricing = $this->calculateRentalPricing($motorcycle, $startDate, $endDate, $request->insurance_type);

            return response()->json([
                'motorcycle' => [
                    'id' => $motorcycle->id,
                    'brand' => $motorcycle->brand,
                    'model' => $motorcycle->model,
                    'daily_rate' => $motorcycle->daily_rate,
                ],
                'period' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                    'days' => $startDate->diffInDays($endDate) + 1,
                ],
                'pricing' => $pricing,
            ]);
        } catch (\Exception $e) {
            Log::error('Error calculating price', [
                'error' => $e->getMessage(),
                'data' => $request->all(),
            ]);

            return response()->json([
                'error' => 'Erro ao calcular preço',
                'message' => 'Tente novamente em alguns instantes',
            ], 500);
        }
    }

    /**
     * Apply filters to the rental query.
     */
    private function applyFilters($query, Request $request): void
    {
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('start_date_from')) {
            $query->where('start_date', '>=', $request->start_date_from);
        }

        if ($request->filled('start_date_to')) {
            $query->where('start_date', '<=', $request->start_date_to);
        }

        if ($request->filled('motorcycle_id')) {
            $query->where('motorcycle_id', $request->motorcycle_id);
        }

        if ($request->filled('overdue')) {
            if ($request->boolean('overdue')) {
                $query->where('status', 'active')
                      ->where('end_date', '<', now())
                      ->whereNull('actual_return_date');
            }
        }
    }

    /**
     * Calculate rental pricing with discounts and fees.
     */
    private function calculateRentalPricing(Motorcycle $motorcycle, Carbon $startDate, Carbon $endDate, ?string $insuranceType = null): array
    {
        $days = $startDate->diffInDays($endDate) + 1;
        $baseCost = $days * $motorcycle->daily_rate;

        // Calculate discounts based on rental duration
        $discount = 0;
        if ($days >= 7 && $days < 30) {
            $discount = $baseCost * 0.10; // 10% discount for weekly rentals
        } elseif ($days >= 30) {
            $discount = $baseCost * 0.20; // 20% discount for monthly rentals
        }

        // Calculate security deposit (20% of base cost, minimum R$ 200)
        $securityDeposit = max($baseCost * 0.20, 200);

        // Calculate insurance fees
        $insuranceFee = 0;
        if ($insuranceType) {
            $insuranceFee = match ($insuranceType) {
                'basic' => $days * 15,    // R$ 15 per day
                'premium' => $days * 25,  // R$ 25 per day
                'full' => $days * 40,     // R$ 40 per day
                default => 0,
            };
        }

        $totalAmount = $baseCost - $discount + $insuranceFee;

        return [
            'base_cost' => $baseCost,
            'discount' => $discount,
            'insurance_fee' => $insuranceFee,
            'security_deposit' => $securityDeposit,
            'total_amount' => $totalAmount,
            'days' => $days,
            'discount_percentage' => $days >= 30 ? 20 : ($days >= 7 ? 10 : 0),
        ];
    }

    /**
     * Calculate cancellation refund and fees.
     */
    private function calculateCancellationRefund(Rental $rental): array
    {
        $hoursUntilStart = $rental->start_date->diffInHours(now());
        $totalPaid = $rental->total_amount;

        if ($hoursUntilStart > 48) {
            // More than 48 hours: full refund
            $refundAmount = $totalPaid;
            $cancellationFee = 0;
        } elseif ($hoursUntilStart > 24) {
            // 24-48 hours: 90% refund
            $refundAmount = $totalPaid * 0.90;
            $cancellationFee = $totalPaid * 0.10;
        } else {
            // Less than 24 hours: 70% refund
            $refundAmount = $totalPaid * 0.70;
            $cancellationFee = $totalPaid * 0.30;
        }

        return [
            'refund_amount' => $refundAmount,
            'cancellation_fee' => $cancellationFee,
            'hours_until_start' => $hoursUntilStart,
            'refund_percentage' => round(($refundAmount / $totalPaid) * 100, 1),
        ];
    }
}
