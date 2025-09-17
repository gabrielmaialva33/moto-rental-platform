<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMotorcycleRequest;
use App\Http\Requests\UpdateMotorcycleRequest;
use App\Http\Resources\MotorcycleCollection;
use App\Http\Resources\MotorcycleResource;
use App\Models\Motorcycle;
use App\Models\Rental;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MotorcycleController extends Controller
{
    /**
     * Display a listing of motorcycles with advanced filtering.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Motorcycle::query()->with(['rentals' => function ($query) {
                $query->whereIn('status', ['reserved', 'active']);
            }]);

            // Apply filters
            $this->applyFilters($query, $request);

            // Apply sorting
            $this->applySorting($query, $request);

            // Paginate results
            $perPage = min($request->input('per_page', 15), 50);
            $motorcycles = $query->paginate($perPage);

            return response()->json(new MotorcycleCollection($motorcycles));
        } catch (\Exception $e) {
            Log::error('Error fetching motorcycles', [
                'error' => $e->getMessage(),
                'filters' => $request->all(),
            ]);

            return response()->json([
                'error' => 'Erro ao buscar motocicletas',
                'message' => 'Tente novamente em alguns instantes',
            ], 500);
        }
    }

    /**
     * Store a newly created motorcycle.
     */
    public function store(StoreMotorcycleRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $data = $request->validated();

            // Handle image uploads
            if ($request->hasFile('images')) {
                $data['images'] = $this->handleImageUploads($request->file('images'));
            }

            $motorcycle = Motorcycle::create($data);

            DB::commit();

            Log::info('Motorcycle created', [
                'motorcycle_id' => $motorcycle->id,
                'brand' => $motorcycle->brand,
                'model' => $motorcycle->model,
                'created_by' => $request->user()->id,
            ]);

            return response()->json([
                'message' => 'Motocicleta criada com sucesso',
                'data' => new MotorcycleResource($motorcycle),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error creating motorcycle', [
                'error' => $e->getMessage(),
                'data' => $request->validated(),
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'error' => 'Erro ao criar motocicleta',
                'message' => 'Tente novamente em alguns instantes',
            ], 500);
        }
    }

    /**
     * Display the specified motorcycle with availability calendar.
     */
    public function show(Motorcycle $motorcycle): JsonResponse
    {
        try {
            $motorcycle->load(['rentals' => function ($query) {
                $query->whereIn('status', ['reserved', 'active', 'completed'])
                      ->orderBy('start_date');
            }]);

            $motorcycleResource = new MotorcycleResource($motorcycle);
            $availabilityCalendar = $this->generateAvailabilityCalendar($motorcycle);

            return response()->json([
                'data' => $motorcycleResource,
                'availability_calendar' => $availabilityCalendar,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching motorcycle', [
                'motorcycle_id' => $motorcycle->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Erro ao buscar motocicleta',
                'message' => 'Tente novamente em alguns instantes',
            ], 500);
        }
    }

    /**
     * Update the specified motorcycle.
     */
    public function update(UpdateMotorcycleRequest $request, Motorcycle $motorcycle): JsonResponse
    {
        DB::beginTransaction();

        try {
            $data = $request->validated();

            // Handle image uploads
            if ($request->hasFile('images')) {
                // Delete old images
                $this->deleteImages($motorcycle->images);
                $data['images'] = $this->handleImageUploads($request->file('images'));
            }

            $motorcycle->update($data);

            DB::commit();

            Log::info('Motorcycle updated', [
                'motorcycle_id' => $motorcycle->id,
                'updated_fields' => array_keys($data),
                'updated_by' => $request->user()->id,
            ]);

            return response()->json([
                'message' => 'Motocicleta atualizada com sucesso',
                'data' => new MotorcycleResource($motorcycle->fresh()),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error updating motorcycle', [
                'motorcycle_id' => $motorcycle->id,
                'error' => $e->getMessage(),
                'data' => $request->validated(),
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'error' => 'Erro ao atualizar motocicleta',
                'message' => 'Tente novamente em alguns instantes',
            ], 500);
        }
    }

    /**
     * Soft delete the specified motorcycle.
     */
    public function destroy(Motorcycle $motorcycle): JsonResponse
    {
        try {
            // Check if motorcycle has active rentals
            $activeRentals = $motorcycle->rentals()
                ->whereIn('status', ['reserved', 'active'])
                ->count();

            if ($activeRentals > 0) {
                return response()->json([
                    'error' => 'Não é possível excluir motocicleta com locações ativas',
                    'message' => 'Aguarde o término das locações em andamento',
                ], 422);
            }

            // Update status to inactive instead of hard delete
            $motorcycle->update(['status' => 'inactive']);

            Log::info('Motorcycle deactivated', [
                'motorcycle_id' => $motorcycle->id,
                'deactivated_by' => request()->user()->id,
            ]);

            return response()->json([
                'message' => 'Motocicleta desativada com sucesso',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deactivating motorcycle', [
                'motorcycle_id' => $motorcycle->id,
                'error' => $e->getMessage(),
                'user_id' => request()->user()->id,
            ]);

            return response()->json([
                'error' => 'Erro ao desativar motocicleta',
                'message' => 'Tente novamente em alguns instantes',
            ], 500);
        }
    }

    /**
     * Check motorcycle availability for a date range.
     */
    public function checkAvailability(Request $request, Motorcycle $motorcycle): JsonResponse
    {
        $request->validate([
            'start_date' => 'required|date|after:today',
            'end_date' => 'required|date|after:start_date',
        ]);

        try {
            $startDate = Carbon::parse($request->start_date);
            $endDate = Carbon::parse($request->end_date);

            $conflictingRentals = $motorcycle->rentals()
                ->whereIn('status', ['reserved', 'active'])
                ->where(function ($query) use ($startDate, $endDate) {
                    $query->whereBetween('start_date', [$startDate, $endDate])
                          ->orWhereBetween('end_date', [$startDate, $endDate])
                          ->orWhere(function ($subQuery) use ($startDate, $endDate) {
                              $subQuery->where('start_date', '<=', $startDate)
                                       ->where('end_date', '>=', $endDate);
                          });
                })
                ->exists();

            $isAvailable = !$conflictingRentals && $motorcycle->status === 'available';

            return response()->json([
                'available' => $isAvailable,
                'motorcycle' => [
                    'id' => $motorcycle->id,
                    'brand' => $motorcycle->brand,
                    'model' => $motorcycle->model,
                    'status' => $motorcycle->status,
                ],
                'period' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                    'days' => $startDate->diffInDays($endDate) + 1,
                ],
                'pricing' => [
                    'daily_rate' => $motorcycle->daily_rate,
                    'total_cost' => $motorcycle->daily_rate * ($startDate->diffInDays($endDate) + 1),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error checking availability', [
                'motorcycle_id' => $motorcycle->id,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Erro ao verificar disponibilidade',
                'message' => 'Tente novamente em alguns instantes',
            ], 500);
        }
    }

    /**
     * Upload additional images for a motorcycle.
     */
    public function uploadImages(Request $request, Motorcycle $motorcycle): JsonResponse
    {
        $request->validate([
            'images' => 'required|array|max:10',
            'images.*' => 'image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        DB::beginTransaction();

        try {
            $newImages = $this->handleImageUploads($request->file('images'));
            $existingImages = $motorcycle->images ?? [];

            // Limit total images to 10
            $allImages = array_merge($existingImages, $newImages);
            if (count($allImages) > 10) {
                $allImages = array_slice($allImages, 0, 10);
            }

            $motorcycle->update(['images' => $allImages]);

            DB::commit();

            Log::info('Motorcycle images uploaded', [
                'motorcycle_id' => $motorcycle->id,
                'new_images_count' => count($newImages),
                'total_images_count' => count($allImages),
                'uploaded_by' => $request->user()->id,
            ]);

            return response()->json([
                'message' => 'Imagens enviadas com sucesso',
                'data' => [
                    'images' => $allImages,
                    'total_count' => count($allImages),
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error uploading images', [
                'motorcycle_id' => $motorcycle->id,
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'error' => 'Erro ao enviar imagens',
                'message' => 'Tente novamente em alguns instantes',
            ], 500);
        }
    }

    /**
     * Apply filters to the motorcycle query.
     */
    private function applyFilters($query, Request $request): void
    {
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('brand')) {
            $query->where('brand', 'like', '%' . $request->brand . '%');
        }

        if ($request->filled('model')) {
            $query->where('model', 'like', '%' . $request->model . '%');
        }

        if ($request->filled('year_from')) {
            $query->where('year', '>=', $request->year_from);
        }

        if ($request->filled('year_to')) {
            $query->where('year', '<=', $request->year_to);
        }

        if ($request->filled('price_min')) {
            $query->where('daily_rate', '>=', $request->price_min);
        }

        if ($request->filled('price_max')) {
            $query->where('daily_rate', '<=', $request->price_max);
        }

        if ($request->filled('engine_capacity_min')) {
            $query->where('engine_capacity', '>=', $request->engine_capacity_min);
        }

        if ($request->filled('engine_capacity_max')) {
            $query->where('engine_capacity', '<=', $request->engine_capacity_max);
        }

        if ($request->filled('available_from') && $request->filled('available_to')) {
            $this->filterByAvailability($query, $request->available_from, $request->available_to);
        }
    }

    /**
     * Filter motorcycles by availability in a date range.
     */
    private function filterByAvailability($query, string $startDate, string $endDate): void
    {
        $query->where('status', 'available')
              ->whereDoesntHave('rentals', function ($rentalQuery) use ($startDate, $endDate) {
                  $rentalQuery->whereIn('status', ['reserved', 'active'])
                              ->where(function ($dateQuery) use ($startDate, $endDate) {
                                  $dateQuery->whereBetween('start_date', [$startDate, $endDate])
                                            ->orWhereBetween('end_date', [$startDate, $endDate])
                                            ->orWhere(function ($subQuery) use ($startDate, $endDate) {
                                                $subQuery->where('start_date', '<=', $startDate)
                                                         ->where('end_date', '>=', $endDate);
                                            });
                              });
              });
    }

    /**
     * Apply sorting to the motorcycle query.
     */
    private function applySorting($query, Request $request): void
    {
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowedSortFields = [
            'created_at', 'updated_at', 'brand', 'model',
            'year', 'daily_rate', 'mileage', 'engine_capacity'
        ];

        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
        }
    }

    /**
     * Handle image uploads.
     */
    private function handleImageUploads(array $images): array
    {
        $uploadedImages = [];

        foreach ($images as $image) {
            $filename = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
            $path = $image->storeAs('motorcycles', $filename, 'public');
            $uploadedImages[] = basename($path);
        }

        return $uploadedImages;
    }

    /**
     * Delete images from storage.
     */
    private function deleteImages(?array $images): void
    {
        if (empty($images)) {
            return;
        }

        foreach ($images as $image) {
            Storage::disk('public')->delete('motorcycles/' . $image);
        }
    }

    /**
     * Generate availability calendar for the next 60 days.
     */
    private function generateAvailabilityCalendar(Motorcycle $motorcycle): array
    {
        $calendar = [];
        $startDate = Carbon::today();
        $endDate = Carbon::today()->addDays(60);

        $rentals = $motorcycle->rentals()
            ->whereIn('status', ['reserved', 'active'])
            ->whereBetween('start_date', [$startDate, $endDate])
            ->orWhereBetween('end_date', [$startDate, $endDate])
            ->get();

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $dateString = $date->format('Y-m-d');
            $isAvailable = true;

            foreach ($rentals as $rental) {
                if ($date->between($rental->start_date, $rental->end_date)) {
                    $isAvailable = false;
                    break;
                }
            }

            $calendar[] = [
                'date' => $dateString,
                'available' => $isAvailable && $motorcycle->status === 'available',
                'day_of_week' => $date->format('l'),
                'is_weekend' => $date->isWeekend(),
            ];
        }

        return $calendar;
    }
}
