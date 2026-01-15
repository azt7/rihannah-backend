<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Tentative booking expiry timestamp
            $table->timestamp('tentative_expiry_at')->nullable()->after('booking_status');

            // No-show flag for management reporting (US-M-05)
            $table->boolean('is_no_show')->default(false)->after('tentative_expiry_at');

            // Index for efficient expiry queries
            $table->index(['booking_status', 'tentative_expiry_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['booking_status', 'tentative_expiry_at']);
            $table->dropColumn(['tentative_expiry_at', 'is_no_show']);
        });
    }
};
