<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(
        protected BookingService $bookingService
    ) {}

    /**
     * List bookings for calendar view.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'unit_id' => 'nullable|exists:units,id',
            'status' => 'nullable|string',
        ]);

        $query = Booking::with(['unit', 'customer', 'creator'])
            ->forCalendar($request->from, $request->to);

        if ($request->unit_id) {
            $query->forUnit($request->unit_id);
        }

        if ($request->status) {
            if ($request->status === 'active') {
                $query->active();
            } elseif ($request->status !== 'all') {
                $query->where('booking_status', $request->status);
            }
        } else {
            // By default, exclude cancelled
            $query->notCancelled();
        }

        $bookings = $query->orderBy('start_date')->get();

        // Transform for calendar
        $calendarEvents = $bookings->map(function ($booking) {
            return [
                'id' => $booking->id,
                'resourceId' => $booking->unit_id,
                'title' => $booking->customer_display_name,
                'start' => $booking->start_date->format('Y-m-d'),
                'end' => $booking->end_date->addDay()->format('Y-m-d'), // FullCalendar end is exclusive
                'backgroundColor' => $this->getStatusColor($booking->booking_status),
                'borderColor' => $this->getStatusColor($booking->booking_status),
                'extendedProps' => [
                    'reference_number' => $booking->reference_number,
                    'customer_name' => $booking->customer_display_name,
                    'customer_phone' => $booking->customer_display_phone,
                    'unit_name' => $booking->unit->name,
                    'price_total' => $booking->price_total,
                    'amount_paid' => $booking->amount_paid,
                    'remaining_amount' => $booking->remaining_amount,
                    'payment_status' => $booking->payment_status,
                    'booking_status' => $booking->booking_status,
                    'tentative_expiry_at' => $booking->tentative_expiry_at?->format('Y-m-d H:i'),
                    'is_no_show' => $booking->is_no_show,
                ],
            ];
        });

        return response()->json([
            'bookings' => $bookings,
            'events' => $calendarEvents,
        ]);
    }

    /**
     * Create a new booking.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unit_id' => 'required|exists:units,id',
            'customer_id' => 'nullable|exists:customers,id',
            'customer_name' => 'required_without:customer_id|string|max:150',
            'customer_phone' => ['required_without:customer_id', 'string', 'max:20', 'regex:/^[\+]?[0-9]{9,15}$/'],
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'price_total' => 'required|numeric|min:0',
            'amount_paid' => 'nullable|numeric|min:0|lte:price_total',
            'payment_method' => 'nullable|in:cash,transfer,card',
            'deposit_amount' => 'nullable|numeric|min:0',
            'reference_number' => 'nullable|string|max:100',
            'booking_status' => 'nullable|in:tentative,confirmed',
            'notes' => 'nullable|string',
        ]);

        $booking = $this->bookingService->create($validated);

        return response()->json([
            'booking' => $booking,
            'message' => 'Booking created successfully',
        ], 201);
    }

    /**
     * Get a single booking.
     */
    public function show(Booking $booking): JsonResponse
    {
        $booking->load(['unit', 'customer', 'creator', 'updater']);

        return response()->json(['booking' => $booking]);
    }

    /**
     * Update a booking.
     */
    public function update(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->isCancelled()) {
            return response()->json([
                'message' => 'Cannot update a cancelled booking',
            ], 422);
        }

        $validated = $request->validate([
            'unit_id' => 'exists:units,id',
            'customer_id' => 'nullable|exists:customers,id',
            'customer_name' => 'nullable|string|max:150',
            'customer_phone' => ['nullable', 'string', 'max:20', 'regex:/^[\+]?[0-9]{9,15}$/'],
            'start_date' => 'date',
            'end_date' => 'date|after_or_equal:start_date',
            'price_total' => 'numeric|min:0',
            'amount_paid' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|in:cash,transfer,card',
            'deposit_amount' => 'nullable|numeric|min:0',
            'reference_number' => 'nullable|string|max:100',
            'booking_status' => 'nullable|in:tentative,confirmed,checked_in,checked_out',
            'notes' => 'nullable|string',
        ]);

        // Validate paid <= total
        $total = $validated['price_total'] ?? $booking->price_total;
        $paid = $validated['amount_paid'] ?? $booking->amount_paid;
        if ($paid > $total) {
            return response()->json([
                'message' => 'Amount paid cannot exceed total price',
                'errors' => ['amount_paid' => ['Amount paid cannot exceed total price']],
            ], 422);
        }

        $booking = $this->bookingService->update($booking, $validated);

        return response()->json([
            'booking' => $booking,
            'message' => 'Booking updated successfully',
        ]);
    }

    /**
     * Cancel a booking.
     */
    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->isCancelled()) {
            return response()->json([
                'message' => 'Booking is already cancelled',
            ], 422);
        }

        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $booking = $this->bookingService->cancel($booking, $request->reason);

        return response()->json([
            'booking' => $booking,
            'message' => 'Booking cancelled successfully',
        ]);
    }

    /**
     * Get WhatsApp URL for a booking.
     */
    public function whatsappUrl(Request $request, Booking $booking): JsonResponse
    {
        $lang = $request->get('lang', 'ar');
        $url = $this->bookingService->getWhatsAppUrl($booking, $lang);

        return response()->json(['url' => $url]);
    }

    /**
     * Search bookings by phone number (US-RA-08).
     * Supports partial search (e.g., last 4 digits).
     */
    public function searchByPhone(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|min:4',
        ]);

        $bookings = $this->bookingService->searchByPhone($request->phone);

        // Transform for search results
        $results = $bookings->map(function ($booking) {
            return [
                'id' => $booking->id,
                'reference_number' => $booking->reference_number,
                'customer_name' => $booking->customer_display_name,
                'customer_phone' => $booking->customer_display_phone,
                'unit_name' => $booking->unit->name,
                'start_date' => $booking->start_date->format('Y-m-d'),
                'end_date' => $booking->end_date->format('Y-m-d'),
                'booking_status' => $booking->booking_status,
                'payment_status' => $booking->payment_status,
                'remaining_amount' => $booking->remaining_amount,
                'price_total' => $booking->price_total,
            ];
        });

        return response()->json([
            'bookings' => $results,
            'count' => $results->count(),
        ]);
    }

    /**
     * Get today's check-ins (US-M-04).
     */
    public function todayCheckIns(): JsonResponse
    {
        $bookings = Booking::with(['unit', 'customer', 'creator'])
            ->checkingInToday()
            ->orderBy('start_date')
            ->get();

        return response()->json([
            'bookings' => $bookings,
            'count' => $bookings->count(),
        ]);
    }

    /**
     * Get today's check-outs (US-M-04).
     */
    public function todayCheckOuts(): JsonResponse
    {
        $bookings = Booking::with(['unit', 'customer', 'creator'])
            ->checkingOutToday()
            ->orderBy('end_date')
            ->get();

        return response()->json([
            'bookings' => $bookings,
            'count' => $bookings->count(),
        ]);
    }

    /**
     * Mark a booking as no-show (US-M-05).
     */
    public function markNoShow(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->isCancelled()) {
            return response()->json([
                'message' => 'Cannot mark a cancelled booking as no-show',
            ], 422);
        }

        $booking->markAsNoShow();

        return response()->json([
            'booking' => $booking->load(['unit', 'customer']),
            'message' => 'Booking marked as no-show',
        ]);
    }

    /**
     * Get status color for calendar.
     */
    private function getStatusColor(string $status): string
    {
        return match ($status) {
            'tentative' => '#f59e0b',   // amber
            'confirmed' => '#10b981',   // green
            'checked_in' => '#3b82f6',  // blue
            'checked_out' => '#6b7280', // gray
            'cancelled' => '#ef4444',   // red
            default => '#10b981',
        };
    }
}
