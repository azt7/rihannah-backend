<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Booking $booking
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('bookings'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'booking.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'booking' => [
                'id' => $this->booking->id,
                'unit_id' => $this->booking->unit_id,
                'unit_name' => $this->booking->unit?->name,
                'customer_name' => $this->booking->customer_display_name,
                'start_date' => $this->booking->start_date->format('Y-m-d'),
                'end_date' => $this->booking->end_date->format('Y-m-d'),
                'price_total' => $this->booking->price_total,
                'amount_paid' => $this->booking->amount_paid,
                'remaining_amount' => $this->booking->remaining_amount,
                'payment_status' => $this->booking->payment_status,
                'booking_status' => $this->booking->booking_status,
            ],
        ];
    }
}
