<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class Rental extends Model
{
    /** @use HasFactory<\Database\Factories\RentalFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'motorcycle_id',
        'start_date',
        'end_date',
        'actual_return_date',
        'daily_rate',
        'total_amount',
        'security_deposit',
        'discount',
        'additional_charges',
        'additional_charges_description',
        'status',
        'payment_status',
        'pickup_location',
        'return_location',
        'notes',
        'insurance_details',
        'initial_mileage',
        'final_mileage',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'actual_return_date' => 'datetime',
        'daily_rate' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'security_deposit' => 'decimal:2',
        'discount' => 'decimal:2',
        'additional_charges' => 'decimal:2',
        'insurance_details' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function motorcycle(): BelongsTo
    {
        return $this->belongsTo(Motorcycle::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeReserved($query)
    {
        return $query->where('status', 'reserved');
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('start_date', [$startDate, $endDate]);
    }

    public function getDurationInDaysAttribute(): int
    {
        return Carbon::parse($this->start_date)->diffInDays(Carbon::parse($this->end_date)) + 1;
    }

    public function calculateTotalAmount(): float
    {
        $baseCost = $this->duration_in_days * $this->daily_rate;
        $totalWithDiscount = $baseCost - $this->discount;
        return $totalWithDiscount + $this->additional_charges;
    }

    public function isOverdue(): bool
    {
        return $this->status === 'active' &&
               $this->end_date < now() &&
               is_null($this->actual_return_date);
    }

    public function markAsActive(): void
    {
        $this->update(['status' => 'active']);
        $this->motorcycle->markAsRented();
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'actual_return_date' => now()
        ]);
        $this->motorcycle->markAsAvailable();
    }

    public function markAsCancelled(): void
    {
        $this->update(['status' => 'cancelled']);
        if ($this->status === 'active') {
            $this->motorcycle->markAsAvailable();
        }
    }
}
