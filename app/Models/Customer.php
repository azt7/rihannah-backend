<?php

namespace App\Models;

use App\Helpers\PhoneHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'full_name',
        'phone_number',
        'notes',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('full_name', 'like', "%{$term}%")
              ->orWhere('phone_number', 'like', "%{$term}%");
        });
    }

    /**
     * Search by phone number with normalization support.
     * Supports partial search (e.g., last 4 digits).
     */
    public function scopeSearchByPhone(Builder $query, string $phone): Builder
    {
        $digits = PhoneHelper::stripToDigits($phone);

        if (strlen($digits) >= 4) {
            return $query->where('phone_number', 'like', "%{$digits}%");
        }

        return $query;
    }

    /**
     * Find customer by exact phone match (after normalization).
     */
    public static function findByPhone(string $phone): ?self
    {
        $normalized = PhoneHelper::normalize($phone);

        return self::where('phone_number', $normalized)->first();
    }

    /**
     * Normalize phone number before saving.
     */
    protected static function booted(): void
    {
        static::saving(function (Customer $customer) {
            if (!empty($customer->phone_number)) {
                $customer->phone_number = PhoneHelper::normalize($customer->phone_number);
            }
        });
    }
}
