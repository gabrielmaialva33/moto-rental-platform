<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Motorcycle extends Model
{
    /** @use HasFactory<\Database\Factories\MotorcycleFactory> */
    use HasFactory;

    protected $fillable = [
        'brand',
        'model',
        'year',
        'plate',
        'color',
        'engine_capacity',
        'mileage',
        'daily_rate',
        'status',
        'description',
        'features',
        'images',
        'last_maintenance_at',
        'next_maintenance_at',
    ];

    protected $casts = [
        'features' => 'array',
        'images' => 'array',
        'daily_rate' => 'decimal:2',
        'last_maintenance_at' => 'datetime',
        'next_maintenance_at' => 'datetime',
    ];

    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class);
    }

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function scopeByBrand($query, $brand)
    {
        return $query->where('brand', $brand);
    }

    public function scopeByPriceRange($query, $min, $max)
    {
        return $query->whereBetween('daily_rate', [$min, $max]);
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    public function markAsRented(): void
    {
        $this->update(['status' => 'rented']);
    }

    public function markAsAvailable(): void
    {
        $this->update(['status' => 'available']);
    }

    public function markAsMaintenance(): void
    {
        $this->update(['status' => 'maintenance']);
    }
}
