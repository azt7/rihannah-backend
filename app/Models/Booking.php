<?php

namespace App\Models;

use App\Helpers\PhoneHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'unit_id',
        'customer_id',
        'customer_name',
        'customer_phone',
        'start_date',
        'end_date',
        'price_total',
        'amount_paid',
        'payment_status',
        'payment_method',
        'deposit_amount',
        'reference_number',
        'booking_status',
        'tentative_expiry_at',
        'is_no_show',
        'notes',
        'cancellation_reason',
        'cancelled_at',
        'cancelled_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'price_total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
            'tentative_expiry_at' => 'datetime',
            'is_no_show' => 'boolean',
        ];
    }

    // Relationships
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    // Accessors
    public function getCustomerDisplayNameAttribute(): string
    {
        if ($this->customer) {
            return $this->customer->full_name;
        }
        return $this->customer_name ?? 'Unknown';
    }

    public function getCustomerDisplayPhoneAttribute(): string
    {
        if ($this->customer) {
            return $this->customer->phone_number;
        }
        return $this->customer_phone ?? '';
    }

    // Scopes
    public function scopeForCalendar(Builder $query, $from, $to): Builder
    {
        return $query->where(function ($q) use ($from, $to) {
            $q->whereBetween('start_date', [$from, $to])
              ->orWhereBetween('end_date', [$from, $to])
              ->orWhere(function ($q2) use ($from, $to) {
                  $q2->where('start_date', '<=', $from)
                     ->where('end_date', '>=', $to);
              });
        });
    }

    public function scopeForUnit(Builder $query, $unitId): Builder
    {
        return $query->where('unit_id', $unitId);
    }

    public function scopeNotCancelled(Builder $query): Builder
    {
        return $query->where('booking_status', '!=', 'cancelled');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('booking_status', ['tentative', 'confirmed', 'checked_in']);
    }

    public function scopeConflicting(Builder $query, $unitId, $startDate, $endDate, $excludeId = null): Builder
    {
        $query->where('unit_id', $unitId)
              ->where('booking_status', '!=', 'cancelled')
              ->where(function ($q) use ($startDate, $endDate) {
                  // Overlap detection: start_date < end_date AND end_date > start_date
                  // We allow checkout day = next check-in day, so we use < and > (not <= and >=)
                  $q->where('start_date', '<', $endDate)
                    ->where('end_date', '>', $startDate);
              });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query;
    }

    // Methods
    public function isCancelled(): bool
    {
        return $this->booking_status === 'cancelled';
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function cancel(User $user, ?string $reason = null): void
    {
        $this->update([
            'booking_status' => 'cancelled',
            'cancellation_reason' => $reason,
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
        ]);
    }

    public function updatePaymentStatus(): void
    {
        $remaining = $this->price_total - $this->amount_paid;

        if ($remaining <= 0) {
            $this->payment_status = 'paid';
        } elseif ($this->amount_paid > 0) {
            $this->payment_status = 'partial';
        } else {
            $this->payment_status = 'unpaid';
        }
    }

    // Scopes for new features
    public function scopeTentativeExpired(Builder $query): Builder
    {
        return $query->where('booking_status', 'tentative')
                     ->whereNotNull('tentative_expiry_at')
                     ->where('tentative_expiry_at', '<=', now());
    }

    public function scopeCheckingInToday(Builder $query): Builder
    {
        return $query->whereDate('start_date', today())
                     ->whereIn('booking_status', ['tentative', 'confirmed']);
    }

    public function scopeCheckingOutToday(Builder $query): Builder
    {
        return $query->whereDate('end_date', today())
                     ->whereIn('booking_status', ['confirmed', 'checked_in']);
    }

    public function scopeNoShows(Builder $query): Builder
    {
        return $query->where('is_no_show', true);
    }

    public function scopeByAgent(Builder $query, int $userId): Builder
    {
        return $query->where('created_by', $userId);
    }

    public function scopeSearchByPhone(Builder $query, string $phone): Builder
    {
        // Strip to digits for flexible matching
        $digits = PhoneHelper::stripToDigits($phone);

        if (strlen($digits) >= 4) {
            // Also try with leading 0 removed (local Saudi format: 05X -> 5X)
            $digitsWithout0 = $digits;
            if (str_starts_with($digits, '0') && strlen($digits) >= 9) {
                $digitsWithout0 = substr($digits, 1);
            }

            return $query->where(function ($q) use ($digits, $digitsWithout0) {
                $q->where('customer_phone', 'like', "%{$digits}%")
                  ->orWhere('customer_phone', 'like', "%{$digitsWithout0}%")
                  ->orWhereHas('customer', function ($q2) use ($digits, $digitsWithout0) {
                      $q2->where('phone_number', 'like', "%{$digits}%")
                         ->orWhere('phone_number', 'like', "%{$digitsWithout0}%");
                  });
            });
        }

        return $query;
    }

    // Methods
    public function isTentative(): bool
    {
        return $this->booking_status === 'tentative';
    }

    public function isExpired(): bool
    {
        return $this->isTentative() &&
               $this->tentative_expiry_at &&
               $this->tentative_expiry_at->isPast();
    }

    public function markAsNoShow(): void
    {
        $this->update(['is_no_show' => true]);
    }

    /**
     * Generate a unique booking reference number.
     * Format: RIH-YYYY-NNNNNN (e.g., RIH-2026-000123)
     */
    public static function generateReference(): string
    {
        $year = date('Y');
        $prefix = "RIH-{$year}-";

        // Get the last reference number for this year
        $lastBooking = self::where('reference_number', 'like', "{$prefix}%")
            ->orderByRaw('CAST(SUBSTRING(reference_number, -6) AS UNSIGNED) DESC')
            ->first();

        if ($lastBooking && preg_match('/(\d{6})$/', $lastBooking->reference_number, $matches)) {
            $nextNumber = intval($matches[1]) + 1;
        } else {
            $nextNumber = 1;
        }

        return $prefix . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
    }

    // Auto-set payment status and normalize phone before saving
    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            // Auto-generate reference number if not set
            if (empty($booking->reference_number)) {
                $booking->reference_number = self::generateReference();
            }

            // Normalize phone number
            if (!empty($booking->customer_phone)) {
                $booking->customer_phone = PhoneHelper::normalize($booking->customer_phone);
            }
        });

        static::saving(function (Booking $booking) {
            $booking->updatePaymentStatus();

            // Normalize phone on update as well
            if ($booking->isDirty('customer_phone') && !empty($booking->customer_phone)) {
                $booking->customer_phone = PhoneHelper::normalize($booking->customer_phone);
            }
        });
    }
}
