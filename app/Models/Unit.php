<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'status',
        'default_price',
        'price_thursday',
        'price_friday',
        'price_saturday',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'default_price' => 'decimal:2',
            'price_thursday' => 'decimal:2',
            'price_friday' => 'decimal:2',
            'price_saturday' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the price for a specific day of the week.
     * 0 = Sunday, 4 = Thursday, 5 = Friday, 6 = Saturday
     */
    public function getPriceForDay(int $dayOfWeek): float
    {
        return match ($dayOfWeek) {
            4 => $this->price_thursday ?? $this->default_price ?? 0,
            5 => $this->price_friday ?? $this->default_price ?? 0,
            6 => $this->price_saturday ?? $this->default_price ?? 0,
            default => $this->default_price ?? 0,
        };
    }

    /**
     * Get the price for a specific date.
     */
    public function getPriceForDate(\DateTimeInterface $date): float
    {
        $dayOfWeek = (int) $date->format('w');
        return $this->getPriceForDay($dayOfWeek);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
