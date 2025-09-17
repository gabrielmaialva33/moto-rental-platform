<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'cpf',
        'rg',
        'cnh',
        'cnh_category',
        'cnh_expiry_date',
        'phone',
        'whatsapp',
        'birth_date',
        'address',
        'city',
        'state',
        'zip_code',
        'role',
        'is_verified',
        'documents',
        'credit_limit',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'cnh_expiry_date' => 'date',
            'birth_date' => 'date',
            'is_verified' => 'boolean',
            'documents' => 'array',
            'credit_limit' => 'decimal:2',
        ];
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function activeRentals(): HasMany
    {
        return $this->hasMany(Rental::class)->where('status', 'active');
    }

    public function completedRentals(): HasMany
    {
        return $this->hasMany(Rental::class)->where('status', 'completed');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isEmployee(): bool
    {
        return $this->role === 'employee';
    }

    public function isCustomer(): bool
    {
        return $this->role === 'customer';
    }

    public function canRent(): bool
    {
        return $this->is_verified &&
               !is_null($this->cnh) &&
               $this->cnh_expiry_date > now();
    }

    public function hasActiveRental(): bool
    {
        return $this->activeRentals()->exists();
    }

    public function getTotalSpentAttribute(): float
    {
        return $this->payments()
                    ->where('status', 'completed')
                    ->where('type', 'rental')
                    ->sum('amount');
    }
}
