<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceRecord extends Model
{
    /** @use HasFactory<\Database\Factories\MaintenanceRecordFactory> */
    use HasFactory;

    protected $fillable = [
        'motorcycle_id',
        'type',
        'maintenance_date',
        'mileage_at_maintenance',
        'cost',
        'description',
        'services_performed',
        'parts_replaced',
        'performed_by',
        'workshop',
        'next_maintenance_date',
        'next_maintenance_mileage',
    ];

    protected $casts = [
        'maintenance_date' => 'date',
        'next_maintenance_date' => 'date',
        'cost' => 'decimal:2',
        'services_performed' => 'array',
        'parts_replaced' => 'array',
    ];

    public function motorcycle(): BelongsTo
    {
        return $this->belongsTo(Motorcycle::class);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByMotorcycle($query, $motorcycleId)
    {
        return $query->where('motorcycle_id', $motorcycleId);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('maintenance_date', [$startDate, $endDate]);
    }

    public function scopeUpcoming($query)
    {
        return $query->whereNotNull('next_maintenance_date')
                     ->where('next_maintenance_date', '>=', now())
                     ->orderBy('next_maintenance_date');
    }

    public function isPreventive(): bool
    {
        return $this->type === 'preventive';
    }

    public function isCorrective(): bool
    {
        return $this->type === 'corrective';
    }

    public function isInspection(): bool
    {
        return $this->type === 'inspection';
    }

    public function isDue(): bool
    {
        if ($this->next_maintenance_date) {
            return $this->next_maintenance_date <= now();
        }
        return false;
    }
}
