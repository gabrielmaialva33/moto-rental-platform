<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MotorcycleResource extends JsonResource
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
            'brand' => $this->brand,
            'model' => $this->model,
            'year' => $this->year,
            'plate' => $this->when($request->user()?->hasRole('admin'), $this->plate),
            'color' => $this->color,
            'engine_capacity' => $this->engine_capacity,
            'mileage' => $this->when($request->user()?->hasRole('admin'), $this->mileage),
            'daily_rate' => $this->daily_rate,
            'status' => $this->status,
            'description' => $this->description,
            'features' => $this->features ?? [],
            'images' => $this->formatImages(),
            'availability' => [
                'is_available' => $this->isAvailable(),
                'next_available_date' => $this->getNextAvailableDate(),
            ],
            'maintenance' => $this->when($request->user()?->hasRole('admin'), [
                'last_maintenance_at' => $this->last_maintenance_at?->format('Y-m-d'),
                'next_maintenance_at' => $this->next_maintenance_at?->format('Y-m-d'),
                'maintenance_status' => $this->getMaintenanceStatus(),
            ]),
            'rental_info' => $this->when($this->relationLoaded('rentals'), [
                'total_rentals' => $this->rentals_count ?? $this->rentals->count(),
                'active_rental' => $this->getActiveRental(),
            ]),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Format images with full URLs.
     */
    private function formatImages(): array
    {
        if (empty($this->images)) {
            return [];
        }

        return array_map(function ($image) {
            if (str_starts_with($image, 'http')) {
                return $image;
            }
            return asset('storage/motorcycles/' . $image);
        }, $this->images);
    }

    /**
     * Get next available date for rental.
     */
    private function getNextAvailableDate(): ?string
    {
        if ($this->isAvailable()) {
            return now()->addDay()->format('Y-m-d');
        }

        $activeRental = $this->rentals()
            ->whereIn('status', ['reserved', 'active'])
            ->orderBy('end_date', 'desc')
            ->first();

        return $activeRental ? $activeRental->end_date->addDay()->format('Y-m-d') : null;
    }

    /**
     * Get maintenance status.
     */
    private function getMaintenanceStatus(): string
    {
        if ($this->status === 'maintenance') {
            return 'em_manutencao';
        }

        if ($this->next_maintenance_at && $this->next_maintenance_at->isPast()) {
            return 'manutencao_atrasada';
        }

        if ($this->next_maintenance_at && $this->next_maintenance_at->diffInDays(now()) <= 7) {
            return 'manutencao_proxima';
        }

        return 'ok';
    }

    /**
     * Get active rental information.
     */
    private function getActiveRental(): ?array
    {
        $activeRental = $this->rentals()
            ->whereIn('status', ['reserved', 'active'])
            ->with('user:id,name,email')
            ->first();

        if (!$activeRental) {
            return null;
        }

        return [
            'id' => $activeRental->id,
            'user' => $activeRental->user ? [
                'id' => $activeRental->user->id,
                'name' => $activeRental->user->name,
                'email' => $activeRental->user->email,
            ] : null,
            'start_date' => $activeRental->start_date->format('Y-m-d'),
            'end_date' => $activeRental->end_date->format('Y-m-d'),
            'status' => $activeRental->status,
        ];
    }

    /**
     * Get additional data to be returned with the resource array.
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'currency' => 'BRL',
                'rate_type' => 'daily',
            ],
        ];
    }
}
