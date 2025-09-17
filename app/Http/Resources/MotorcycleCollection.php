<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class MotorcycleCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => MotorcycleResource::collection($this->collection),
            'filters' => $this->getAvailableFilters(),
            'stats' => $this->getStats(),
        ];
    }

    /**
     * Get available filter options.
     */
    private function getAvailableFilters(): array
    {
        $motorcycles = $this->collection;

        return [
            'brands' => $motorcycles->pluck('brand')->unique()->sort()->values()->toArray(),
            'years' => $motorcycles->pluck('year')->unique()->sort()->values()->toArray(),
            'engine_capacities' => $motorcycles->pluck('engine_capacity')->unique()->sort()->values()->toArray(),
            'price_range' => [
                'min' => $motorcycles->min('daily_rate'),
                'max' => $motorcycles->max('daily_rate'),
            ],
            'statuses' => $motorcycles->pluck('status')->unique()->sort()->values()->toArray(),
        ];
    }

    /**
     * Get collection statistics.
     */
    private function getStats(): array
    {
        $motorcycles = $this->collection;

        return [
            'total' => $motorcycles->count(),
            'available' => $motorcycles->where('status', 'available')->count(),
            'rented' => $motorcycles->where('status', 'rented')->count(),
            'maintenance' => $motorcycles->where('status', 'maintenance')->count(),
            'average_daily_rate' => round($motorcycles->avg('daily_rate'), 2),
            'most_popular_brand' => $motorcycles->groupBy('brand')->map->count()->sortDesc()->keys()->first(),
        ];
    }

    /**
     * Get additional data to be returned with the resource collection.
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'currency' => 'BRL',
                'rate_type' => 'daily',
                'pagination' => $this->resource instanceof \Illuminate\Pagination\LengthAwarePaginator ? [
                    'current_page' => $this->resource->currentPage(),
                    'last_page' => $this->resource->lastPage(),
                    'per_page' => $this->resource->perPage(),
                    'total' => $this->resource->total(),
                    'from' => $this->resource->firstItem(),
                    'to' => $this->resource->lastItem(),
                ] : null,
            ],
        ];
    }
}
