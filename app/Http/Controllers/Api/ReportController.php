<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Get financial summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $from = Carbon::parse($request->from)->startOfDay();
        $to = Carbon::parse($request->to)->endOfDay();

        // Get bookings in date range
        $bookings = Booking::whereBetween('start_date', [$from, $to])
            ->notCancelled()
            ->get();

        // Calculate totals
        $totalRevenue = $bookings->sum('price_total');
        $totalCollected = $bookings->sum('amount_paid');
        $totalOutstanding = $bookings->sum('remaining_amount');
        $bookingsCount = $bookings->count();

        // Breakdown by payment status
        $byPaymentStatus = $bookings->groupBy('payment_status')->map->count();

        // Breakdown by unit
        $byUnit = Booking::select('unit_id', DB::raw('COUNT(*) as count'), DB::raw('SUM(price_total) as revenue'))
            ->whereBetween('start_date', [$from, $to])
            ->notCancelled()
            ->groupBy('unit_id')
            ->with('unit:id,name')
            ->get()
            ->map(function ($item) {
                return [
                    'unit_id' => $item->unit_id,
                    'unit_name' => $item->unit?->name,
                    'bookings_count' => $item->count,
                    'revenue' => $item->revenue,
                ];
            });

        return response()->json([
            'period' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
            'summary' => [
                'total_revenue' => $totalRevenue,
                'total_collected' => $totalCollected,
                'total_outstanding' => $totalOutstanding,
                'bookings_count' => $bookingsCount,
            ],
            'by_payment_status' => [
                'paid' => $byPaymentStatus->get('paid', 0),
                'partial' => $byPaymentStatus->get('partial', 0),
                'unpaid' => $byPaymentStatus->get('unpaid', 0),
            ],
            'by_unit' => $byUnit,
        ]);
    }

    /**
     * Get occupancy rates.
     */
    public function occupancy(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $from = Carbon::parse($request->from);
        $to = Carbon::parse($request->to);
        $totalDays = $from->diffInDays($to) + 1;

        $units = Unit::active()->ordered()->get();
        $occupancy = [];

        foreach ($units as $unit) {
            // Get bookings that overlap with the period
            $bookings = Booking::where('unit_id', $unit->id)
                ->notCancelled()
                ->where(function ($q) use ($from, $to) {
                    $q->whereBetween('start_date', [$from, $to])
                      ->orWhereBetween('end_date', [$from, $to])
                      ->orWhere(function ($q2) use ($from, $to) {
                          $q2->where('start_date', '<=', $from)
                             ->where('end_date', '>=', $to);
                      });
                })
                ->get();

            // Calculate occupied days
            $occupiedDays = 0;
            foreach ($bookings as $booking) {
                $bookingStart = $booking->start_date->max($from);
                $bookingEnd = $booking->end_date->min($to);
                $occupiedDays += $bookingStart->diffInDays($bookingEnd) + 1;
            }

            // Cap at total days (in case of overlapping calculations)
            $occupiedDays = min($occupiedDays, $totalDays);
            $occupancyRate = $totalDays > 0 ? round(($occupiedDays / $totalDays) * 100, 1) : 0;

            $occupancy[] = [
                'unit_id' => $unit->id,
                'unit_name' => $unit->name,
                'total_days' => $totalDays,
                'occupied_days' => $occupiedDays,
                'available_days' => $totalDays - $occupiedDays,
                'occupancy_rate' => $occupancyRate,
            ];
        }

        // Average occupancy
        $avgOccupancy = count($occupancy) > 0
            ? round(collect($occupancy)->avg('occupancy_rate'), 1)
            : 0;

        return response()->json([
            'period' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'total_days' => $totalDays,
            ],
            'average_occupancy' => $avgOccupancy,
            'by_unit' => $occupancy,
        ]);
    }

    /**
     * Export bookings to CSV.
     */
    public function export(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $from = Carbon::parse($request->from);
        $to = Carbon::parse($request->to);

        $bookings = Booking::with(['unit', 'customer'])
            ->whereBetween('start_date', [$from, $to])
            ->orderBy('start_date')
            ->get();

        $filename = "bookings_{$from->format('Ymd')}_{$to->format('Ymd')}.csv";

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($bookings) {
            $file = fopen('php://output', 'w');

            // UTF-8 BOM for Excel
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // Header row
            fputcsv($file, [
                'Booking ID',
                'Unit',
                'Customer Name',
                'Customer Phone',
                'Start Date',
                'End Date',
                'Total Price',
                'Amount Paid',
                'Remaining',
                'Payment Status',
                'Booking Status',
                'Created At',
            ]);

            foreach ($bookings as $booking) {
                fputcsv($file, [
                    $booking->id,
                    $booking->unit->name,
                    $booking->customer_display_name,
                    $booking->customer_display_phone,
                    $booking->start_date->format('Y-m-d'),
                    $booking->end_date->format('Y-m-d'),
                    $booking->price_total,
                    $booking->amount_paid,
                    $booking->remaining_amount,
                    $booking->payment_status,
                    $booking->booking_status,
                    $booking->created_at->format('Y-m-d H:i'),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Get agent activity report (US-M-03).
     */
    public function agentActivity(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'agent_id' => 'nullable|exists:users,id',
        ]);

        $from = Carbon::parse($request->from)->startOfDay();
        $to = Carbon::parse($request->to)->endOfDay();

        $query = Booking::with(['unit', 'creator', 'updater'])
            ->whereBetween('created_at', [$from, $to]);

        if ($request->agent_id) {
            $query->where('created_by', $request->agent_id);
        }

        $bookings = $query->orderBy('created_at', 'desc')->get();

        // Aggregate by agent
        $byAgent = User::whereIn('id', $bookings->pluck('created_by')->unique())
            ->get()
            ->map(function ($user) use ($bookings, $from, $to) {
                $agentBookings = $bookings->where('created_by', $user->id);

                return [
                    'agent_id' => $user->id,
                    'agent_name' => $user->name,
                    'bookings_created' => $agentBookings->count(),
                    'total_revenue' => $agentBookings->sum('price_total'),
                    'total_collected' => $agentBookings->sum('amount_paid'),
                    'cancelled_count' => $agentBookings->where('booking_status', 'cancelled')->count(),
                    'tentative_count' => $agentBookings->where('booking_status', 'tentative')->count(),
                    'confirmed_count' => $agentBookings->where('booking_status', 'confirmed')->count(),
                ];
            });

        return response()->json([
            'period' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
            'by_agent' => $byAgent,
            'bookings' => $bookings->map(function ($booking) {
                return [
                    'id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'unit_name' => $booking->unit->name,
                    'customer_name' => $booking->customer_display_name,
                    'start_date' => $booking->start_date->format('Y-m-d'),
                    'end_date' => $booking->end_date->format('Y-m-d'),
                    'price_total' => $booking->price_total,
                    'booking_status' => $booking->booking_status,
                    'created_by' => $booking->creator?->name,
                    'updated_by' => $booking->updater?->name,
                    'created_at' => $booking->created_at->format('Y-m-d H:i'),
                ];
            }),
            'total_bookings' => $bookings->count(),
        ]);
    }

    /**
     * Get cancellations and no-shows report (US-M-05).
     */
    public function cancellations(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $from = Carbon::parse($request->from)->startOfDay();
        $to = Carbon::parse($request->to)->endOfDay();

        // Get cancellations
        $cancellations = Booking::with(['unit', 'customer', 'creator', 'canceller'])
            ->where('booking_status', 'cancelled')
            ->whereBetween('cancelled_at', [$from, $to])
            ->orderBy('cancelled_at', 'desc')
            ->get();

        // Get no-shows
        $noShows = Booking::with(['unit', 'customer', 'creator'])
            ->where('is_no_show', true)
            ->whereBetween('start_date', [$from, $to])
            ->orderBy('start_date', 'desc')
            ->get();

        // Calculate lost revenue
        $cancelledRevenue = $cancellations->sum('price_total');
        $noShowRevenue = $noShows->sum('price_total');

        // Group cancellations by reason
        $byReason = $cancellations->groupBy(function ($booking) {
            return $booking->cancellation_reason ?? 'No reason provided';
        })->map->count();

        return response()->json([
            'period' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
            'summary' => [
                'total_cancellations' => $cancellations->count(),
                'total_no_shows' => $noShows->count(),
                'cancelled_revenue' => $cancelledRevenue,
                'no_show_revenue' => $noShowRevenue,
                'total_lost_revenue' => $cancelledRevenue + $noShowRevenue,
            ],
            'by_reason' => $byReason,
            'cancellations' => $cancellations->map(function ($booking) {
                return [
                    'id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'unit_name' => $booking->unit->name,
                    'customer_name' => $booking->customer_display_name,
                    'start_date' => $booking->start_date->format('Y-m-d'),
                    'end_date' => $booking->end_date->format('Y-m-d'),
                    'price_total' => $booking->price_total,
                    'cancellation_reason' => $booking->cancellation_reason,
                    'cancelled_at' => $booking->cancelled_at?->format('Y-m-d H:i'),
                    'cancelled_by' => $booking->canceller?->name ?? 'System',
                ];
            }),
            'no_shows' => $noShows->map(function ($booking) {
                return [
                    'id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'unit_name' => $booking->unit->name,
                    'customer_name' => $booking->customer_display_name,
                    'start_date' => $booking->start_date->format('Y-m-d'),
                    'end_date' => $booking->end_date->format('Y-m-d'),
                    'price_total' => $booking->price_total,
                ];
            }),
        ]);
    }

    /**
     * Get today's dashboard summary (US-M-04).
     */
    public function todayDashboard(): JsonResponse
    {
        $today = Carbon::today();

        // Today's check-ins
        $checkIns = Booking::with(['unit', 'customer'])
            ->checkingInToday()
            ->get();

        // Today's check-outs
        $checkOuts = Booking::with(['unit', 'customer'])
            ->checkingOutToday()
            ->get();

        // Unpaid check-ins
        $unpaidCheckIns = $checkIns->where('payment_status', '!=', 'paid');

        // Tentative bookings expiring soon (within 2 hours)
        $expiringTentative = Booking::where('booking_status', 'tentative')
            ->whereNotNull('tentative_expiry_at')
            ->where('tentative_expiry_at', '<=', now()->addHours(2))
            ->where('tentative_expiry_at', '>', now())
            ->with(['unit', 'customer'])
            ->get();

        return response()->json([
            'date' => $today->format('Y-m-d'),
            'check_ins' => [
                'count' => $checkIns->count(),
                'unpaid_count' => $unpaidCheckIns->count(),
                'bookings' => $checkIns->map(function ($booking) {
                    return [
                        'id' => $booking->id,
                        'reference_number' => $booking->reference_number,
                        'unit_name' => $booking->unit->name,
                        'customer_name' => $booking->customer_display_name,
                        'customer_phone' => $booking->customer_display_phone,
                        'remaining_amount' => $booking->remaining_amount,
                        'payment_status' => $booking->payment_status,
                        'booking_status' => $booking->booking_status,
                    ];
                }),
            ],
            'check_outs' => [
                'count' => $checkOuts->count(),
                'bookings' => $checkOuts->map(function ($booking) {
                    return [
                        'id' => $booking->id,
                        'reference_number' => $booking->reference_number,
                        'unit_name' => $booking->unit->name,
                        'customer_name' => $booking->customer_display_name,
                        'remaining_amount' => $booking->remaining_amount,
                        'payment_status' => $booking->payment_status,
                    ];
                }),
            ],
            'expiring_tentative' => [
                'count' => $expiringTentative->count(),
                'bookings' => $expiringTentative->map(function ($booking) {
                    return [
                        'id' => $booking->id,
                        'reference_number' => $booking->reference_number,
                        'unit_name' => $booking->unit->name,
                        'customer_name' => $booking->customer_display_name,
                        'customer_phone' => $booking->customer_display_phone,
                        'tentative_expiry_at' => $booking->tentative_expiry_at->format('Y-m-d H:i'),
                        'remaining_amount' => $booking->remaining_amount,
                    ];
                }),
            ],
        ]);
    }
}
