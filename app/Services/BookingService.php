<?php

namespace App\Services;

use App\Helpers\PhoneHelper;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\AuditLog;
use App\Events\BookingCreated;
use App\Events\BookingUpdated;
use App\Events\BookingCancelled;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingService
{
    /**
     * Check if there's a booking conflict for a unit.
     */
    public function hasConflict(int $unitId, string $startDate, string $endDate, ?int $excludeId = null): ?Booking
    {
        return Booking::conflicting($unitId, $startDate, $endDate, $excludeId)->first();
    }

    /**
     * Create a new booking.
     */
    public function create(array $data): Booking
    {
        return DB::transaction(function () use ($data) {
            // Check for conflicts
            $conflict = $this->hasConflict(
                $data['unit_id'],
                $data['start_date'],
                $data['end_date']
            );

            if ($conflict) {
                throw ValidationException::withMessages([
                    'dates' => $this->getConflictMessage($conflict),
                ]);
            }

            // Handle customer - either use existing or create inline
            $customerId = $data['customer_id'] ?? null;
            $existingCustomer = null;

            if (!$customerId && !empty($data['customer_phone'])) {
                // Normalize phone and check if customer exists (US-RA-04)
                $normalizedPhone = PhoneHelper::normalize($data['customer_phone']);
                $existingCustomer = Customer::findByPhone($normalizedPhone);
                if ($existingCustomer) {
                    $customerId = $existingCustomer->id;
                }
            }

            // Calculate tentative expiry if booking is tentative (US-RA-03)
            $tentativeExpiryAt = null;
            $bookingStatus = $data['booking_status'] ?? 'confirmed';
            if ($bookingStatus === 'tentative') {
                $expiryHours = (int) Setting::get('tentative_expiry_hours', 4);
                $tentativeExpiryAt = Carbon::now()->addHours($expiryHours);
            }

            // Create the booking (reference_number is auto-generated in model)
            $booking = Booking::create([
                'unit_id' => $data['unit_id'],
                'customer_id' => $customerId,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'price_total' => $data['price_total'],
                'amount_paid' => $data['amount_paid'] ?? 0,
                'payment_method' => $data['payment_method'] ?? null,
                'deposit_amount' => $data['deposit_amount'] ?? null,
                'booking_status' => $bookingStatus,
                'tentative_expiry_at' => $tentativeExpiryAt,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            // Log the creation
            AuditLog::log($booking, 'created', null, $booking->toArray());

            // Broadcast event
            event(new BookingCreated($booking));

            $result = $booking->load(['unit', 'customer', 'creator']);

            // Add existing customer info to response for frontend notification
            if ($existingCustomer && !$data['customer_id']) {
                $result->existing_customer_detected = true;
                $result->existing_customer = $existingCustomer;
            }

            return $result;
        });
    }

    /**
     * Update an existing booking.
     */
    public function update(Booking $booking, array $data): Booking
    {
        return DB::transaction(function () use ($booking, $data) {
            $oldValues = $booking->toArray();

            // Check for conflicts if dates or unit changed
            $unitId = $data['unit_id'] ?? $booking->unit_id;
            $startDate = $data['start_date'] ?? $booking->start_date->format('Y-m-d');
            $endDate = $data['end_date'] ?? $booking->end_date->format('Y-m-d');

            if (
                $unitId != $booking->unit_id ||
                $startDate != $booking->start_date->format('Y-m-d') ||
                $endDate != $booking->end_date->format('Y-m-d')
            ) {
                $conflict = $this->hasConflict($unitId, $startDate, $endDate, $booking->id);
                if ($conflict) {
                    throw ValidationException::withMessages([
                        'dates' => $this->getConflictMessage($conflict),
                    ]);
                }
            }

            // Handle status change from tentative to confirmed (US-RA-06)
            $newStatus = $data['booking_status'] ?? $booking->booking_status;
            $tentativeExpiryAt = $booking->tentative_expiry_at;

            if ($booking->booking_status === 'tentative' && $newStatus === 'confirmed') {
                // Clear expiry when confirmed
                $tentativeExpiryAt = null;
            } elseif ($newStatus === 'tentative' && $booking->booking_status !== 'tentative') {
                // Set new expiry if changing to tentative
                $expiryHours = (int) Setting::get('tentative_expiry_hours', 4);
                $tentativeExpiryAt = Carbon::now()->addHours($expiryHours);
            }

            $booking->update([
                'unit_id' => $data['unit_id'] ?? $booking->unit_id,
                'customer_id' => $data['customer_id'] ?? $booking->customer_id,
                'customer_name' => $data['customer_name'] ?? $booking->customer_name,
                'customer_phone' => $data['customer_phone'] ?? $booking->customer_phone,
                'start_date' => $data['start_date'] ?? $booking->start_date,
                'end_date' => $data['end_date'] ?? $booking->end_date,
                'price_total' => $data['price_total'] ?? $booking->price_total,
                'amount_paid' => $data['amount_paid'] ?? $booking->amount_paid,
                'payment_method' => $data['payment_method'] ?? $booking->payment_method,
                'deposit_amount' => $data['deposit_amount'] ?? $booking->deposit_amount,
                'booking_status' => $newStatus,
                'tentative_expiry_at' => $tentativeExpiryAt,
                'is_no_show' => $data['is_no_show'] ?? $booking->is_no_show,
                'notes' => $data['notes'] ?? $booking->notes,
                'updated_by' => auth()->id(),
            ]);

            // Log the update
            AuditLog::log($booking, 'updated', $oldValues, $booking->toArray());

            // Broadcast event
            event(new BookingUpdated($booking));

            return $booking->load(['unit', 'customer', 'creator']);
        });
    }

    /**
     * Cancel a booking.
     */
    public function cancel(Booking $booking, ?string $reason = null): Booking
    {
        return DB::transaction(function () use ($booking, $reason) {
            $oldValues = $booking->toArray();

            $booking->cancel(auth()->user(), $reason);

            // Log the cancellation
            AuditLog::log($booking, 'cancelled', $oldValues, $booking->toArray());

            // Broadcast event
            event(new BookingCancelled($booking));

            return $booking->load(['unit', 'customer']);
        });
    }

    /**
     * Generate WhatsApp URL for a booking.
     */
    public function getWhatsAppUrl(Booking $booking, string $lang = 'ar'): string
    {
        $templateKey = $lang === 'ar' ? 'whatsapp_template' : 'whatsapp_template_en';
        $template = Setting::get($templateKey, $this->getDefaultTemplate($lang));

        $phone = $booking->customer_display_phone;
        // Remove any non-digit characters except + at the start
        $phone = preg_replace('/[^0-9]/', '', $phone);
        // Ensure Saudi format
        if (str_starts_with($phone, '0')) {
            $phone = '966' . substr($phone, 1);
        } elseif (!str_starts_with($phone, '966')) {
            $phone = '966' . $phone;
        }

        $message = $this->parseTemplate($template, $booking);
        $encodedMessage = urlencode($message);

        return "https://wa.me/{$phone}?text={$encodedMessage}";
    }

    /**
     * Parse WhatsApp template with booking data.
     */
    private function parseTemplate(string $template, Booking $booking): string
    {
        $replacements = [
            '{customer_name}' => $booking->customer_display_name,
            '{phone}' => $booking->customer_display_phone,
            '{unit_name}' => $booking->unit->name,
            '{start_date}' => $booking->start_date->format('Y-m-d'),
            '{end_date}' => $booking->end_date->format('Y-m-d'),
            '{total_price}' => number_format($booking->price_total, 2),
            '{paid}' => number_format($booking->amount_paid, 2),
            '{remaining}' => number_format($booking->remaining_amount, 2),
            '{booking_id}' => $booking->id,
            '{reference}' => $booking->reference_number ?? 'N/A',
            '{booking_reference}' => $booking->reference_number ?? 'N/A',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Get default WhatsApp template.
     * Now includes booking reference (US-SYS-04).
     */
    private function getDefaultTemplate(string $lang): string
    {
        if ($lang === 'ar') {
            return "حجز الشاليه ✅\nرقم الحجز: {reference}\nالاسم: {customer_name}\nالوحدة: {unit_name}\nالتاريخ: {start_date} إلى {end_date}\nالسعر: {total_price} ر.س\nالمدفوع: {paid} ر.س\nالمتبقي: {remaining} ر.س";
        }

        return "Chalet Booking ✅\nRef: {reference}\nName: {customer_name}\nUnit: {unit_name}\nDates: {start_date} to {end_date}\nTotal: {total_price} SAR\nPaid: {paid} SAR\nRemaining: {remaining} SAR";
    }

    /**
     * Get conflict error message.
     */
    private function getConflictMessage(Booking $conflict): string
    {
        return sprintf(
            'This unit is already booked from %s to %s.',
            $conflict->start_date->format('M d, Y'),
            $conflict->end_date->format('M d, Y')
        );
    }

    /**
     * Auto-cancel expired tentative bookings (US-SYS-02).
     * Called by scheduled job.
     *
     * @return int Number of bookings cancelled
     */
    public function cancelExpiredTentativeBookings(): int
    {
        $expiredBookings = Booking::tentativeExpired()->get();
        $count = 0;

        foreach ($expiredBookings as $booking) {
            DB::transaction(function () use ($booking) {
                $oldValues = $booking->toArray();

                $booking->update([
                    'booking_status' => 'cancelled',
                    'cancellation_reason' => 'Tentative booking expired (auto-cancelled)',
                    'cancelled_at' => now(),
                    // cancelled_by is null for system actions
                ]);

                // Log the auto-cancellation
                AuditLog::log($booking, 'auto_cancelled', $oldValues, $booking->toArray());

                // Broadcast event
                event(new BookingCancelled($booking));
            });

            $count++;
        }

        return $count;
    }

    /**
     * Get bookings for a specific agent (created_by).
     */
    public function getByAgent(int $userId, ?string $from = null, ?string $to = null)
    {
        $query = Booking::with(['unit', 'customer'])
            ->byAgent($userId)
            ->orderBy('created_at', 'desc');

        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }

        return $query->get();
    }

    /**
     * Search bookings by phone number (US-RA-08).
     * Supports partial search (e.g., last 4 digits).
     */
    public function searchByPhone(string $phone): \Illuminate\Database\Eloquent\Collection
    {
        return Booking::with(['unit', 'customer', 'creator'])
            ->searchByPhone($phone)
            ->orderBy('start_date', 'desc')
            ->limit(50)
            ->get();
    }
}
