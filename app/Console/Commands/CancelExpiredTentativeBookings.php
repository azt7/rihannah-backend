<?php

namespace App\Console\Commands;

use App\Services\BookingService;
use Illuminate\Console\Command;

class CancelExpiredTentativeBookings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bookings:cancel-expired-tentative';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-cancel tentative bookings that have passed their expiry time (US-SYS-02)';

    /**
     * Execute the console command.
     */
    public function handle(BookingService $bookingService): int
    {
        $this->info('Checking for expired tentative bookings...');

        $count = $bookingService->cancelExpiredTentativeBookings();

        if ($count > 0) {
            $this->info("Cancelled {$count} expired tentative booking(s).");
        } else {
            $this->info('No expired tentative bookings found.');
        }

        return Command::SUCCESS;
    }
}
