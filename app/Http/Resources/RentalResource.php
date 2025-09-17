<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RentalResource extends JsonResource
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
            'user' => $this->when($this->relationLoaded('user'), [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
                'cnh_number' => $this->when($request->user()?->hasRole('admin'), $this->user->cnh_number),
            ]),
            'motorcycle' => $this->when($this->relationLoaded('motorcycle'), [
                'id' => $this->motorcycle->id,
                'brand' => $this->motorcycle->brand,
                'model' => $this->motorcycle->model,
                'year' => $this->motorcycle->year,
                'plate' => $this->when($request->user()?->hasRole('admin'), $this->motorcycle->plate),
                'color' => $this->motorcycle->color,
                'engine_capacity' => $this->motorcycle->engine_capacity,
                'images' => $this->motorcycle->images ? array_slice($this->motorcycle->images, 0, 1) : [],
            ]),
            'dates' => [
                'start_date' => $this->start_date->format('Y-m-d'),
                'end_date' => $this->end_date->format('Y-m-d'),
                'actual_return_date' => $this->actual_return_date?->format('Y-m-d H:i:s'),
                'duration_days' => $this->duration_in_days,
            ],
            'financial' => [
                'daily_rate' => $this->daily_rate,
                'total_amount' => $this->total_amount,
                'security_deposit' => $this->security_deposit,
                'discount' => $this->discount,
                'additional_charges' => $this->additional_charges,
                'additional_charges_description' => $this->additional_charges_description,
                'calculated_total' => $this->calculateTotalAmount(),
            ],
            'status' => [
                'rental_status' => $this->status,
                'payment_status' => $this->payment_status,
                'is_overdue' => $this->isOverdue(),
                'days_overdue' => $this->getDaysOverdue(),
            ],
            'locations' => [
                'pickup_location' => $this->pickup_location,
                'return_location' => $this->return_location,
            ],
            'mileage' => $this->when($request->user()?->hasRole('admin'), [
                'initial_mileage' => $this->initial_mileage,
                'final_mileage' => $this->final_mileage,
                'total_mileage' => $this->final_mileage ? ($this->final_mileage - $this->initial_mileage) : null,
            ]),
            'insurance_details' => $this->insurance_details ?? [],
            'payments' => $this->when($this->relationLoaded('payments'),
                PaymentResource::collection($this->payments)
            ),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get number of days overdue.
     */
    private function getDaysOverdue(): int
    {
        if (!$this->isOverdue()) {
            return 0;
        }

        return $this->end_date->diffInDays(now());
    }

    /**
     * Get additional data to be returned with the resource array.
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'currency' => 'BRL',
                'can_cancel' => $this->canBeCancelled(),
                'can_extend' => $this->canBeExtended(),
                'penalties' => $this->calculatePenalties(),
            ],
        ];
    }

    /**
     * Check if rental can be cancelled.
     */
    private function canBeCancelled(): bool
    {
        return in_array($this->status, ['reserved']) &&
               $this->start_date->diffInHours(now()) > 24;
    }

    /**
     * Check if rental can be extended.
     */
    private function canBeExtended(): bool
    {
        return in_array($this->status, ['active']) &&
               !$this->isOverdue();
    }

    /**
     * Calculate potential penalties.
     */
    private function calculatePenalties(): array
    {
        $penalties = [];

        if ($this->isOverdue()) {
            $daysOverdue = $this->getDaysOverdue();
            $dailyPenalty = $this->daily_rate * 0.5; // 50% of daily rate as penalty
            $penalties['overdue'] = [
                'days' => $daysOverdue,
                'daily_penalty' => $dailyPenalty,
                'total_penalty' => $daysOverdue * $dailyPenalty,
            ];
        }

        if ($this->status === 'cancelled' && $this->start_date->diffInHours(now()) < 24) {
            $penalties['cancellation'] = [
                'amount' => $this->daily_rate * 0.3, // 30% of daily rate
                'reason' => 'Cancelamento com menos de 24h de antecedência',
            ];
        }

        return $penalties;
    }
}
